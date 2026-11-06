#!/bin/sh
# The backup service's health check (compose.yaml): healthy while the newest
# good backup is younger than ATLAS_BACKUP_EVERY_HOURS plus two hours, or,
# before the first one, for two hours after the service started.
# `docker compose ps backup` then shows a backup loop that runs but no longer
# backs anything up. Atlas's scheduler reads the same file and tells the
# administrators (atlas:check-backups).
set -eu

status=/backups/status
[ -f "$status" ] || exit 1

value() { sed -n "s/^$1=//p" "$status" | tail -n 1; }
now=$(date +%s)
every=$(( ${ATLAS_BACKUP_EVERY_HOURS:-24} * 3600 ))
success=$(value last_success)
started=$(value started)

if [ -n "$success" ]; then
    [ $(( now - success )) -le $(( every + 7200 )) ]
else
    [ -n "$started" ] && [ $(( now - started )) -le 7200 ]
fi
