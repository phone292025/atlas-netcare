#!/bin/sh
# Back up the Atlas database into ./backups on the host.
#
#   docker compose exec db sh /atlas/backup.sh
#
# Runs inside the database container, whose pg_dump matches the server and
# writes the file itself: a dump piped out through PowerShell's `>` comes out
# corrupted. pg_dump reads one consistent snapshot, so Atlas can keep running.
#
# The file holds every account's email address and password hash. Keep it
# private: backups/ is in .gitignore and .dockerignore. restore.sh proves a
# file restores.
set -eu

database=${1:-atlas}
name="${database}-$(date -u +%Y%m%d-%H%M%SZ).dump"
log=$(mktemp)
trap 'rm -f "$log"' EXIT

if ! pg_dump -U "$POSTGRES_USER" -d "$database" --format=custom --file="/backups/$name" 2> "$log"; then
    cat "$log" >&2
    exit 1
fi
cat "$log" >&2

echo "Backed up $database to backups/$name ($(du -h "/backups/$name" | cut -f1))."

# TimescaleDB's own catalog has foreign keys in a circle, and pg_dump warns
# about it on every dump. The warning is about data-only dumps, and this is a
# full one: restore.sh --check proves it restores.
if grep -q 'circular foreign-key' "$log"; then
    echo "The warning above is about TimescaleDB's own catalog, and matters only to data-only dumps. This is a full one."
fi
echo "To prove it restores: docker compose exec db sh atlas/restore.sh backups/$name --check"
