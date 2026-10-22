#!/bin/sh
# Put a database restored by restore.sh in place of the live one.
#
#   docker compose stop app worker scheduler
#   docker compose exec db sh atlas/use-restored.sh atlas_restored
#   docker compose start app worker scheduler
#
# Nothing is dropped: the live database is renamed to atlas_before_<time>,
# to be dropped by hand once the restored data looks right. The two renames
# happen in one transaction, so a failure leaves both databases as they were.
set -eu

restored=${1:?Usage: use-restored.sh <restored database>}
user=$POSTGRES_USER
live=atlas
old="atlas_before_$(date -u +%Y%m%d_%H%M%S)"

case "$restored" in
    "$live" | *[!a-z0-9_]* | [!a-z]*)
        echo "\"$restored\" is not the name of a restored database." >&2
        exit 1 ;;
esac

if [ -z "$(psql -U "$user" -d postgres -tAc "SELECT 1 FROM pg_database WHERE datname = '$restored'")" ]; then
    echo "There is no database called $restored. Nothing was changed." >&2
    exit 1
fi

connected() {
    psql -U "$user" -d postgres -tAc \
        "SELECT count(*) FROM pg_stat_activity WHERE datname IN ('$live', '$restored') $1"
}

if [ "$(connected "AND backend_type = 'client backend'")" != 0 ]; then
    echo "Something is still connected to $live or $restored. Nothing was changed." >&2
    echo "Stop Atlas first: docker compose stop app worker scheduler" >&2
    exit 1
fi

# TimescaleDB keeps a worker connected to every database, and a database with
# a connection can't be renamed. Stop the workers for the swap, and wait for
# them to go.
workers() { psql -U "$user" -d "$live" -v ON_ERROR_STOP=1 -qtAc "SELECT _timescaledb_functions.${1}_background_workers()" > /dev/null; }

stopped=false
swapped=false
finish() {
    status=$?
    if [ "$status" -eq 0 ] || [ "$stopped" = false ]; then
        return
    fi
    if [ "$swapped" = false ]; then
        workers start || true
        echo "Nothing was renamed, and TimescaleDB's workers are running again." >&2
    else
        echo "The swap is done, but TimescaleDB's workers didn't start again: docker compose restart db" >&2
    fi
}
trap finish EXIT

workers stop
stopped=true
for _ in 1 2 3 4 5 6 7 8 9 10; do
    [ "$(connected "")" = 0 ] && break
    sleep 1
done

# Something that connected since the check above makes the rename fail, and
# then neither database is renamed.
psql -U "$user" -d postgres -v ON_ERROR_STOP=1 -qc \
    "ALTER DATABASE $live RENAME TO $old; ALTER DATABASE $restored RENAME TO $live"
swapped=true

workers start

echo "$restored is now $live. The database it replaced is $old: drop it once the restored data looks right."
