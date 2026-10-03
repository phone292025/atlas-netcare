<?php

namespace App\Services\Telemetry;

use App\Enums\AlarmType;
use App\Enums\ApStatus;
use App\Enums\TicketCategory;
use App\Enums\TicketPriority;
use App\Enums\UserRole;
use App\Models\AccessPoint;
use App\Models\User;
use App\Services\Shifts\AlarmService;
use App\Services\Tickets\TicketService;
use Closure;
use Illuminate\Support\Collection;

/**
 * One collection round, and everything the system does about what it finds.
 *
 * Fill any gap since the last round, take a reading from every access point,
 * sweep the silent ones, then act on each status change. The scheduled command
 * and the Network monitor's "Generate sample" button both run this, so both
 * raise the same tickets and alerts.
 *
 * @see App\Console\Commands\CollectTelemetry
 * @see docs/modules/M5-telemetry-and-dashboard.md
 */
class TelemetryCollector
{
    public function __construct(
        private readonly TelemetryService $telemetry,
        private readonly TicketService $tickets,
        private readonly AlarmService $alarms,
        private readonly TelemetryBackfill $backfill,
    ) {}

    /**
     * Run one round. `$report` receives each line worth telling a person, as a
     * level (`info` or `warn`) and a message; the command prints them.
     *
     * @param  (Closure(string, string): void)|null  $report
     * @return Collection<int, array{access_point: AccessPoint, from: ApStatus, to: ApStatus}>
     */
    public function tick(?Closure $report = null): Collection
    {
        $report ??= static function (string $level, string $message): void {};

        /*
         * The scheduler was off. Without this the charts came back to a single
         * point and an empty day, and the only way out was a backfill by hand.
         * Before the live reading, so classification sees the filled history.
         * Simulated driver only: silence from real kit is a fact.
         */
        if ($gap = $this->backfill->fillGap()) {
            $report('info', 'Filled the gap since '.$gap['from']->campus()->format('j M, H:i')
                .' with '.number_format($gap['written']).' simulated readings.');
        }

        // Readings that arrived, plus access points that sent nothing. The
        // sweep runs even when collection returned nothing at all, so a
        // driver failing wholesale does not leave everything looking healthy.
        $transitions = $this->telemetry->collectAndStore()
            ->concat($this->telemetry->markStaleOffline());

        foreach ($transitions as $change) {
            $ap = $change['access_point'];
            $line = "{$ap->ap_code}: {$change['from']->label()} → {$change['to']->label()}";
            $report($change['to'] === ApStatus::Online ? 'info' : 'warn', $line);

            /*
             * Engineers are alerted on the transition only, never on every
             * reading, or a six-hour outage rings the bell 360 times (FR-305).
             *
             * Degraded alerts only from Online. An access point coming back
             * from an outage passes through Degraded, because its unreachable
             * readings count as 100% packet loss; that is recovery, not a new
             * anomaly. And at most once per cooldown per access point: at peak
             * one hovering near a threshold flaps every few minutes.
             */
            if ($change['to'] === ApStatus::Offline) {
                $this->raiseOfflineTicket($ap, $report);
                $this->alertEngineers(AlarmType::ApOffline, $ap, "{$ap->ap_code} is not responding", $ap->location?->label());
            } elseif ($change['to'] === ApStatus::Degraded && $change['from'] === ApStatus::Online
                && ! $this->alarms->raisedRecently(AlarmType::ApDegraded, $ap, (int) config('atlas.thresholds.degraded_alert_cooldown_min'))) {
                $why = 'Latency, packet loss or load has been over its threshold for '
                    .config('atlas.thresholds.consecutive_breach').' readings in a row.';

                $this->alertEngineers(
                    AlarmType::ApDegraded,
                    $ap,
                    "{$ap->ap_code} is degraded",
                    $ap->location ? "{$ap->location->label()}. {$why}" : $why,
                );
            }
        }

        return $transitions;
    }

    /**
     * Through AlarmService, so this module knows nothing about how an alarm is
     * stored, scheduled or delivered. The ticket is the record of work; the
     * alarm is what puts it in front of somebody.
     */
    private function alertEngineers(AlarmType $type, AccessPoint $ap, string $title, ?string $message): void
    {
        $this->alarms->raiseForRole($type, UserRole::Engineer, $title, $message, related: $ap);
    }

    /**
     * An access point going offline raises exactly one ticket (FR-503): only
     * on the transition, and not while an earlier ticket about it is still
     * open, whether raised by hand or by a previous flap.
     *
     * @param  Closure(string, string): void  $report
     */
    private function raiseOfflineTicket(AccessPoint $ap, Closure $report): void
    {
        if ($this->tickets->hasOutstandingFor($ap)) {
            return;
        }

        $reporter = $this->systemReporter();

        if (! $reporter) {
            // Say so rather than failing the round: the reading is stored.
            $report('warn', "No administrator to attribute the auto-ticket for {$ap->ap_code}.");

            return;
        }

        // An absolute campus time, because the description is saved: "2
        // hours ago" would still say so next week.
        $this->tickets->open([
            'title' => "{$ap->ap_code} is not responding",
            'description' => "Raised automatically: no response from {$ap->ap_code}"
                .($ap->location ? " in {$ap->location->label()}" : '')
                .'. Last seen '.($ap->last_seen_at?->campus()->format('j M Y, H:i') ?? 'never').'.',
            'category' => TicketCategory::Network->value,
            'priority' => TicketPriority::Critical->value,
            'location_id' => $ap->location_id,
        ], $reporter, $ap, isDemo: (bool) $ap->is_demo);

        $report('warn', "Raised a ticket for {$ap->ap_code}.");
    }

    /**
     * Who an automatic ticket is attributed to: the first administrator, who
     * stands in for the collector, since `reported_by` is not nullable. Also
     * when the round was started from the browser: the person pressing the
     * button did not find the fault.
     */
    private function systemReporter(): ?User
    {
        return User::query()->active()->role(UserRole::Admin)->orderBy('id')->first();
    }
}
