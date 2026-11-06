<?php

namespace App\Services\Operations;

use App\Enums\AlarmType;
use App\Enums\UserRole;
use App\Models\Alarm;
use App\Services\Shifts\AlarmService;
use Illuminate\Support\Carbon;

/**
 * Whether the scheduled backups are being made, proven and copied.
 *
 * The backup service (docker/postgres/bin/backup-loop.sh) writes how each
 * attempt went into backups/status, which the scheduler's container has
 * read-only. This reads it, and the administrators get an in-app alarm when
 * the newest good backup is late, the last attempt failed, or the copy off
 * this machine is behind. A backup loop can run for weeks backing nothing up;
 * nobody looks at ./backups until they need it.
 *
 * At most one alarm per `backups.alert_every_min`.
 */
class BackupHealth
{
    public function __construct(private readonly AlarmService $alarms) {}

    /** @return list<string> what is wrong, in words */
    public function problems(): array
    {
        if (! config('atlas.backups.expected')) {
            return [];
        }

        $status = $this->status();

        if ($status === null) {
            return ['The backup service has never written its status, so no backup is known to exist. Check it: docker compose ps backup, and docker compose logs backup.'];
        }

        $problems = [];
        $at = fn (string $key) => isset($status[$key]) && ctype_digit($status[$key]) ? Carbon::createFromTimestamp((int) $status[$key]) : null;
        $success = $at('last_success');
        $failure = $at('last_failure');
        $started = $at('started');
        $late = (int) ($status['every_hours'] ?? 24) + (int) config('atlas.backups.late_after_hours');

        if ($failure && (! $success || $failure->greaterThan($success))) {
            $problems[] = 'The last backup failed: '.($status['last_error'] ?? 'no reason given').'. docker compose logs backup says more.';
        }

        if ($success && $success->lessThan(now()->subHours($late))) {
            $problems[] = 'The newest good backup is '.(int) $success->diffInHours(now()).' hours old ('.($status['last_file'] ?? 'unknown file').'), and one is due every '.($status['every_hours'] ?? 24).' hours.';
        } elseif (! $success && $started && $started->lessThan(now()->subHours((int) config('atlas.backups.late_after_hours')))) {
            $problems[] = 'No backup has succeeded since the backup service started '.$started->diffForHumans().'.';
        }

        if ($success && ($status['verified'] ?? 'yes') !== 'yes') {
            $problems[] = 'Backups are not being restored to check them (ATLAS_BACKUP_VERIFY=false), so nobody knows whether they restore.';
        }

        if ($success && ($status['copy'] ?? 'no') === 'yes') {
            $copied = $at('last_copy');

            if (! $copied || $copied->lessThan($success)) {
                $problems[] = 'The newest backup was not copied to ATLAS_BACKUP_COPY_DIR, so the only copy is on this machine. Check that the folder is mounted and writable.';
            }
        }

        return $problems;
    }

    /** Alarm the administrators about any problem, once per period. Returns how many were told. */
    public function alertIfUnhealthy(): int
    {
        $problems = $this->problems();

        if ($problems === []) {
            return 0;
        }

        $recently = Alarm::query()
            ->where('type', AlarmType::BackupUnhealthy)
            ->where('created_at', '>=', now()->subMinutes((int) config('atlas.backups.alert_every_min')))
            ->exists();

        if ($recently) {
            return 0;
        }

        return $this->alarms->raiseForRole(AlarmType::BackupUnhealthy, UserRole::Admin, 'Backups are not working', implode(' ', $problems))->count();
    }

    /** @return array<string, string>|null the status file's key=value lines */
    private function status(): ?array
    {
        $file = (string) config('atlas.backups.status_file');

        if (! is_file($file) || ($lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)) === false) {
            return null;
        }

        $status = [];
        foreach ($lines as $line) {
            [$key, $value] = array_pad(explode('=', $line, 2), 2, '');
            $status[trim($key)] = trim($value);
        }

        return $status;
    }
}
