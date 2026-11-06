#!/bin/sh
# Scheduled backups, run by the stack's `backup` service (compose.yaml).
#
# Every ATLAS_BACKUP_EVERY_HOURS (24) it:
#   1. runs backup.sh against the database service
#   2. restores the new dump into a scratch database with restore.sh --check,
#      which compares every row with the file and drops it again, unless
#      ATLAS_BACKUP_VERIFY=false. A dump that doesn't restore counts as a
#      failed backup
#   3. copies it to /copy when ATLAS_BACKUP_COPY_DIR is set: another disk, a
#      mounted network share, anything that isn't this machine's disk
#   4. deletes dumps older than ATLAS_BACKUP_KEEP_DAYS (14), in both places
#
# It checks the newest dump's age rather than counting from its own start,
# so restarting the stack doesn't take an extra backup or skip one.
#
# Each step's outcome goes into backups/status, which the backup service's
# health check and Atlas's scheduler read (atlas:check-backups): the
# administrators get an alarm when the newest good backup is too old or the
# last attempt failed.
set -eu

every_hours=${ATLAS_BACKUP_EVERY_HOURS:-24}
keep_days=${ATLAS_BACKUP_KEEP_DAYS:-14}
verify=${ATLAS_BACKUP_VERIFY:-true}
copy=${ATLAS_BACKUP_COPY:-}
status=/backups/status

# key=value lines, replaced whole so a reader never sees half a file. A
# value is one line, without "=".
set_status() {
    tmp=$(mktemp /backups/.status.XXXXXX)
    { [ -f "$status" ] && grep -v "^$1=" "$status" || true; } > "$tmp"
    printf '%s=%s\n' "$1" "$(printf '%s' "$2" | tr '\n=' '  ' | cut -c1-300)" >> "$tmp"
    chmod 644 "$tmp"
    mv "$tmp" "$status"
}

failed() {
    echo "$1" >&2
    set_status last_failure "$(date +%s)"
    set_status last_error "$1"
}

echo "Backups every ${every_hours} h into backups/, kept ${keep_days} days."
[ -n "$copy" ] && echo "Each one is copied to ATLAS_BACKUP_COPY_DIR."
[ "$verify" = false ] && echo "ATLAS_BACKUP_VERIFY=false: backups are not restored to check them."
set_status started "$(date +%s)"
set_status every_hours "$every_hours"
set_status copy "$([ -n "$copy" ] && echo yes || echo no)"

while true; do
    newest=$(find /backups -maxdepth 1 -name 'atlas-*.dump' -mmin -$((every_hours * 60)) | head -n 1)

    if [ -z "$newest" ]; then
        log=$(mktemp)
        if ! sh /atlas/backup.sh > "$log" 2>&1; then
            cat "$log" >&2
            failed "pg_dump failed: $(grep -v '^$' "$log" | tail -n 1)"
            rm -f "$log"
            sleep 600
            continue
        fi
        cat "$log"
        rm -f "$log"
        file=$(ls -t /backups/atlas-*.dump | head -n 1)

        if [ "$verify" != false ]; then
            # A check that failed last time leaves its scratch database to
            # look at, and restore.sh refuses one that exists.
            psql -U "$POSTGRES_USER" -d postgres -qc "DROP DATABASE IF EXISTS atlas_restore_check" > /dev/null
            log=$(mktemp)
            if ! sh /atlas/restore.sh "$file" --check > "$log" 2>&1; then
                cat "$log" >&2
                # A dump that doesn't restore is worse than none: it looks
                # like a backup. It is kept to look at, under another name.
                mv "$file" "$file.failed-check"
                failed "$(basename "$file") did not restore: $(grep -v '^$' "$log" | tail -n 1)"
                rm -f "$log"
                sleep 600
                continue
            fi
            cat "$log"
            rm -f "$log"
        fi

        if [ -n "$copy" ]; then
            if cp "$file" "/copy/.$(basename "$file").partial" && mv "/copy/.$(basename "$file").partial" "/copy/$(basename "$file")"; then
                echo "Copied $(basename "$file") to ATLAS_BACKUP_COPY_DIR."
                find /copy -maxdepth 1 -name 'atlas-*.dump' -mtime +"$keep_days" -print -delete | sed 's/^/Deleted old copy /'
                set_status last_copy "$(date +%s)"
            else
                # The backup itself is good, so this is not a failed backup:
                # Atlas reports a copy older than the newest backup.
                rm -f "/copy/.$(basename "$file").partial"
                echo "$(basename "$file") is backed up here but could not be copied to ATLAS_BACKUP_COPY_DIR." >&2
            fi
        fi

        set_status last_success "$(date +%s)"
        set_status last_file "$(basename "$file")"
        set_status last_size "$(du -h "$file" | cut -f1)"
        set_status verified "$([ "$verify" != false ] && echo yes || echo no)"
        find /backups -maxdepth 1 -name 'atlas-*.dump' -mtime +"$keep_days" -print -delete | sed 's/^/Deleted old backup /'
        # A dump or copy a killed process left half written.
        find /backups /copy -maxdepth 1 -name '.*.partial' -mtime +1 -delete
    fi

    sleep 600
done
