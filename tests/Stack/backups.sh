#!/usr/bin/env bash
# End to end, on a running stack with the backup service: the loop's first
# backup is made, restored into a scratch database to prove it, copied to
# ATLAS_BACKUP_COPY_DIR, and recorded in backups/status, which turns the
# service healthy and which atlas:check-backups in the scheduler reads as on
# time. Then a newer backup with no copy is written into the status, and the
# scheduler must report the copy as behind.
#
# Run from the project folder after `docker compose up`, with
# COMPOSE_PROFILES=backup and ATLAS_BACKUP_COPY_DIR set, and the same Compose
# settings in the environment. CI's Docker stack job runs it.
set -euo pipefail

BACKUPS="${ATLAS_BACKUP_DIR:-./backups}"
COPY="${ATLAS_BACKUP_COPY_DIR:?Set ATLAS_BACKUP_COPY_DIR for this check}"

value() { sed -n "s/^$1=//p" "$BACKUPS/status" 2> /dev/null | tail -n 1; }

echo "Waiting for the first backup, its restore check and its copy."
for _ in $(seq 1 120); do
    [ -n "$(value last_success)" ] && break
    [ -n "$(value last_failure)" ] && { echo "FAILED: $(value last_error)"; docker compose logs --no-color backup | tail -n 30; exit 1; }
    sleep 2
done

file=$(value last_file)
[ -n "$file" ] || { echo "FAILED: no backup after 4 minutes"; docker compose logs --no-color backup | tail -n 30; exit 1; }
echo "  backed up $file ($(value last_size)), verified=$(value verified)"
[ "$(value verified)" = yes ] || { echo "FAILED: the backup was not restored to check it"; exit 1; }
docker compose logs --no-color backup | grep -q "identical to the backup" || { echo "FAILED: no restore check in the log"; exit 1; }
cmp -s "$BACKUPS/$file" "$COPY/$file" || { echo "FAILED: $file is not in ATLAS_BACKUP_COPY_DIR"; exit 1; }
echo "  restored into a scratch database and identical; copied to ATLAS_BACKUP_COPY_DIR"

leftover=$(docker compose exec -T db psql -U atlas -d postgres -tAc "SELECT count(*) FROM pg_database WHERE datname = 'atlas_restore_check'")
[ "$leftover" = 0 ] || { echo "FAILED: the scratch database was left behind"; exit 1; }

for _ in $(seq 1 30); do
    [ "$(docker inspect -f '{{.State.Health.Status}}' "$(docker compose ps -q backup)")" = healthy ] && break
    sleep 2
done
[ "$(docker inspect -f '{{.State.Health.Status}}' "$(docker compose ps -q backup)")" = healthy ] || { echo "FAILED: the backup service is not healthy"; exit 1; }
echo "  the backup service reports healthy"

# Run here and in CI, the copy folder is on the same disk as backups/, as a
# careless setup would be: the loop must notice, and the scheduler say so
# and alarm the administrator. On separate storage it must read on time.
said=$(docker compose exec -T scheduler php artisan atlas:check-backups)
echo "$said" | sed 's/^/  scheduler: /'
if [ "$(value copy_same_disk)" = yes ]; then
    grep -q "same filesystem" <<< "$said" && grep -q "Told 1 administrator" <<< "$said" \
        || { echo "FAILED: a copy on the same disk was not reported"; exit 1; }
    echo "  the copy folder is on the same disk as backups/, and that is reported"
else
    grep -q "on time" <<< "$said" || { echo "FAILED: atlas:check-backups did not read the backup as on time"; exit 1; }
fi

# A newer backup that could not be copied: the scheduler must say so. The
# command must also reach the database: an exit code other than 0 fails
# this script.
docker compose exec -T backup sh -c '
    tmp=$(mktemp /backups/.status.XXXXXX)
    grep -v "^last_success=" /backups/status > "$tmp"
    echo "last_success=$(( $(date +%s) + 60 ))" >> "$tmp"
    chmod 644 "$tmp"; mv "$tmp" /backups/status'
said=$(docker compose exec -T scheduler php artisan atlas:check-backups)
grep -q "not copied to ATLAS_BACKUP_COPY_DIR" <<< "$said" \
    || { echo "FAILED: a copy that fell behind was not reported. The scheduler said:"; echo "$said"; exit 1; }
echo "  a copy that fell behind is reported"

# A dump that fails leaves no file the loop could take for a backup.
if docker compose exec -T backup sh /atlas/backup.sh no_such_database > /dev/null 2>&1; then
    echo "FAILED: a dump of a database that doesn't exist succeeded"; exit 1
fi
leftovers=$(find "$BACKUPS" -maxdepth 1 -name '*no_such_database*' | wc -l)
[ "$leftovers" -eq 0 ] || { echo "FAILED: a failed dump left $leftovers files in backups/"; exit 1; }
echo "  a failed dump leaves nothing behind"

echo "Backups end to end: made, proven, copied, watched."
