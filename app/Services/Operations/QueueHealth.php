<?php

namespace App\Services\Operations;

use App\Enums\AlarmType;
use App\Enums\UserRole;
use App\Models\Alarm;
use App\Services\Shifts\AlarmService;
use Illuminate\Support\Facades\DB;

/**
 * Whether the queue worker is doing its job (roadmap 2.6's alerts depend on
 * it).
 *
 * Incidents, their tickets and their email and Telegram alerts all run on
 * the queue. A stopped worker, or one failing every job, would leave every
 * outage silent. The scheduler, a separate process, asks this every few
 * minutes, and the administrators get an in-app alarm: those are delivered
 * by the scheduler's own `atlas:dispatch-alarms`, so the warning gets
 * through even when the worker is the thing that is down.
 *
 * At most one alarm per `queue.alert_every_min`, so a dead worker overnight
 * is one alarm, not ninety-six.
 */
class QueueHealth
{
    public function __construct(private readonly AlarmService $alarms) {}

    /** @return list<string> what is wrong, in words */
    public function problems(): array
    {
        $problems = [];
        $waitLimit = (int) config('atlas.queue.stuck_after_min');

        // The database queue stores available_at as a Unix time.
        $oldest = DB::table('jobs')->whereNull('reserved_at')->min('available_at');

        if ($oldest !== null && (int) $oldest < now()->subMinutes($waitLimit)->getTimestamp()) {
            $problems[] = 'A job has waited more than '.$waitLimit.' minutes: the queue worker looks stopped. Incidents are not being opened or alerted. Check the worker service: docker compose ps worker.';
        }

        $failed = DB::table('failed_jobs')->where('failed_at', '>=', now()->subMinutes((int) config('atlas.queue.alert_every_min')))->count();

        if ($failed >= (int) config('atlas.queue.failed_jobs_alert')) {
            $problems[] = "{$failed} background jobs failed in the last ".config('atlas.queue.alert_every_min').' minutes. docker compose exec app php artisan queue:failed lists them.';
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
            ->where('type', AlarmType::QueueUnhealthy)
            ->where('created_at', '>=', now()->subMinutes((int) config('atlas.queue.alert_every_min')))
            ->exists();

        if ($recently) {
            return 0;
        }

        return $this->alarms->raiseForRole(AlarmType::QueueUnhealthy, UserRole::Admin, 'Background jobs are not running', implode(' ', $problems))->count();
    }
}
