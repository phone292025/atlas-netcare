#!/bin/sh
# Restore a backup into a NEW database, and prove the data matches the file.
#
#   docker compose exec db sh atlas/restore.sh backups/<file>.dump <new database>
#   docker compose exec db sh atlas/restore.sh backups/<file>.dump --check
#
# It refuses a database that already exists, so it can't overwrite the live
# one. With --check it restores into a scratch database, compares, and drops
# that database again: the way to find out whether a backup is any good. The
# README says how to put a restored database in place of the live one.
set -eu
set -o pipefail

usage="Usage: restore.sh <backup file> <new database> | --check"
file=${1:?$usage}
target=${2:?$usage}
user=$POSTGRES_USER

check=false
if [ "$target" = "--check" ]; then
    check=true
    target=atlas_restore_check
fi

case "$target" in
    *[!a-z0-9_]* | [!a-z]*)
        echo "\"$target\" is not a plain database name: lower-case letters, digits and _ only." >&2
        exit 1 ;;
esac

if [ ! -f "$file" ]; then
    echo "There is no backup file at $file." >&2
    exit 1
fi

if [ -n "$(psql -U "$user" -d postgres -tAc "SELECT 1 FROM pg_database WHERE datname = '$target'")" ]; then
    echo "A database called $target already exists. Nothing was changed." >&2
    exit 1
fi

copy=$(mktemp)
log=$(mktemp)
expected=$(mktemp)
actual=$(mktemp)
created=false

# On any failure after the database exists, say so: the next run refuses it.
finish() {
    status=$?
    rm -f "$copy" "$log" "$expected" "$actual"
    if [ "$status" -ne 0 ] && [ "$created" = true ]; then
        echo "$target was left in place to look at. Drop it when done:" >&2
        echo "  docker compose exec db psql -U $user -d postgres -c \"DROP DATABASE $target\"" >&2
    fi
}
trap finish EXIT

psql -U "$user" -d postgres -v ON_ERROR_STOP=1 -qc "CREATE DATABASE $target"
created=true

# TimescaleDB has to exist before its catalog is restored, and restoring mode
# keeps its background jobs out of the way until pg_restore is done.
psql -U "$user" -d "$target" -v ON_ERROR_STOP=1 -qtA \
    -c "CREATE EXTENSION IF NOT EXISTS timescaledb" \
    -c "SELECT timescaledb_pre_restore()" > /dev/null
pg_restore -U "$user" -d "$target" --exit-on-error "$file"
psql -U "$user" -d "$target" -v ON_ERROR_STOP=1 -qtAc "SELECT timescaledb_post_restore()" > /dev/null

# The restore is right when the restored tables hold exactly the rows in the
# file. The restored database is dumped the way backup.sh dumps, and both
# dumps are written out as SQL by the same command, so tables come out in the
# same order. Comments and the random \restrict keys differ between any two
# dumps, so they are left out. The hypertable's chunks and the hourly
# aggregate's rows live in _timescaledb_internal, so that schema is compared
# too: on public alone, the readings were never checked.
if ! pg_dump -U "$user" -d "$target" --format=custom --file="$copy" 2> "$log"; then
    cat "$log" >&2
    exit 1
fi
data() { pg_restore --data-only --schema=public --schema=_timescaledb_internal --file=- "$1" | grep -v -e '^--' -e '^\\restrict ' -e '^\\unrestrict '; }
data "$file" > "$expected"
data "$copy" > "$actual"

if ! cmp -s "$expected" "$actual"; then
    echo "The data in $target differs from the backup:" >&2
    diff "$expected" "$actual" | head -n 20 >&2 || true
    exit 1
fi

tables=$(psql -U "$user" -d "$target" -tAc \
    "SELECT count(*) FROM information_schema.tables WHERE table_schema IN ('public', '_timescaledb_internal') AND table_type = 'BASE TABLE'")
rows=$(awk '/^COPY .* FROM stdin;$/ { inside = 1; next } /^\\\.$/ { inside = 0; next } inside { n++ } END { print n + 0 }' "$expected")

echo "Restored $file into $target: $tables tables and $rows rows, identical to the backup."

if [ "$check" = true ]; then
    psql -U "$user" -d postgres -v ON_ERROR_STOP=1 -qc "DROP DATABASE $target"
    echo "The backup is good. $target was dropped again."
fi
