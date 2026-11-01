#!/bin/sh
# Scheduled backups, run by the stack's `backup` service (compose.yaml).
#
# Every ATLAS_BACKUP_EVERY_HOURS (24) it runs backup.sh against the database
# service, then deletes dumps older than ATLAS_BACKUP_KEEP_DAYS (14). It
# checks the newest dump's age rather than counting from its own start, so
# restarting the stack doesn't take an extra backup or skip one.
#
# The dumps land in ./backups on this machine. A copy somewhere else, another
# disk or another machine, is still the owner's job: a backup on the same
# disk as the database is lost with it.
set -eu

every_hours=${ATLAS_BACKUP_EVERY_HOURS:-24}
keep_days=${ATLAS_BACKUP_KEEP_DAYS:-14}

echo "Backups every ${every_hours} h into backups/, kept ${keep_days} days."

while true; do
    newest=$(find /backups -maxdepth 1 -name 'atlas-*.dump' -mmin -$((every_hours * 60)) | head -n 1)

    if [ -z "$newest" ]; then
        if sh /atlas/backup.sh; then
            find /backups -maxdepth 1 -name 'atlas-*.dump' -mtime +"$keep_days" -print -delete | sed 's/^/Deleted old backup /'
        else
            echo "The backup failed; trying again in 10 minutes." >&2
            sleep 600
            continue
        fi
    fi

    sleep 600
done
