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
 * Take a reading from every access point, sweep the silent ones, then act on
 * each status change: an access point going offline raises one ticket and
 * alarms the engineers.
 *
 * Lifted out of `atlas:collect-telemetry` so that the scheduled command and the
 * Network monitor's "Generate sample" button run exactly the same code. With
 * the reactions left in the command, a sample taken from the browser would
 * have stored the readings and silently skipped the ticket and the alarm.
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
    ) {}

    /**
     * Run one round.
     *
     * `$report` receives each line worth telling a person — a level (`info`
     * or `warn`) and the message. The command prints them; the browser has no
     * use for them and passes nothing.
     *
     * @param  (Closure(string, string): void)|null  $report
     * @return Collection<int, array{access_point: AccessPoint, from: ApStatus, to: ApStatus}>
     */
    public function tick(?Closure $report = null): Collection
    {
        $report ??= static function (string $level, string $message): void {};

        /*
         * Two sources of transition, deliberately merged.
         *
         * collectAndStore() reacts to readings that arrived. markStaleOffline()
         * catches the access points that sent NOTHING — a device a real SNMP
         * driver could not enumerate is simply absent from the batch, and
         * before this it kept its last known status forever while the
         * dashboard reported it online.
         *
         * The stale sweep runs even when collection returned nothing at all,
         * which is the case that matters most: a driver failing wholesale used
         * to leave every access point looking healthy.
         */
        $transitions = $this->telemetry->collectAndStore()
            ->concat($this->telemetry->markStaleOffline());

        foreach ($transitions as $change) {
            $ap = $change['access_point'];
            $line = "{$ap->ap_code}: {$change['from']->label()} → {$change['to']->label()}";
            $report($change['to'] === ApStatus::Online ? 'info' : 'warn', $line);

            if ($change['to'] === ApStatus::Offline) {
                $this->raiseOfflineTicket($ap, $report);

                /*
                 * And tell the engineers directly (FR-506). The ticket is the
                 * record of work; the alarm is what puts it in front of
                 * somebody. Raised through AlarmService, so M5 knows nothing
                 * about how an alarm is stored, scheduled or delivered.
                 *
                 * On the transition only, exactly like the ticket — otherwise
                 * a six-hour outage rings the bell 360 times.
                 */
                $this->alarms->raiseForRole(
                    AlarmType::ApOffline,
                    UserRole::Engineer,
                    "{$ap->ap_code} is not responding",
                    $ap->location?->label(),
                    related: $ap,
                );
            }
        }

        return $transitions;
    }

    /**
     * An access point going offline raises exactly one ticket (FR-503).
     *
     * Two guards, both needed. This runs only on the TRANSITION into offline,
     * not on every offline reading — otherwise a six-hour outage files 360
     * tickets. And `hasOutstandingFor()` covers the case where somebody has
     * already reported it by hand, or where the AP flaps in and out and the
     * first ticket is still open.
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
            // Nobody to attribute it to. Say so rather than failing the whole
            // collection run — the reading itself is already safely stored.
            $report('warn', "No administrator to attribute the auto-ticket for {$ap->ap_code}.");

            return;
        }

        /*
         * An absolute time, not diffForHumans(). This text is SAVED with the
         * ticket and read later: "Last seen 2 hours ago" written today still
         * said "2 hours ago" next week, when it had long stopped being true.
         * On the campus clock, because a person reads it.
         */
        $this->tickets->open([
            'title' => "{$ap->ap_code} is not responding",
            'description' => "Raised automatically: no response from {$ap->ap_code}"
                .($ap->location ? " in {$ap->location->label()}" : '')
                .'. Last seen '.($ap->last_seen_at?->campus()->format('j M Y, H:i') ?? 'never').'.',
            'category' => TicketCategory::Network->value,
            'priority' => TicketPriority::Critical->value,
            'location_id' => $ap->location_id,
        ], $reporter, $ap, isDemo: (bool) $ap->is_demo);
        // isDemo: a ticket the system raised about a demonstration access
        // point is demonstration data, and goes when the purge runs.

        $report('warn', "Raised a ticket for {$ap->ap_code}.");
    }

    /**
     * Who an automatic ticket is attributed to.
     *
     * `reported_by` is not nullable — a ticket with no reporter is a ticket
     * nobody can be asked about. The first administrator stands in for the
     * system, which is honest: an administrator configured the collector.
     * That holds when the round is started from the browser too — the person
     * who pressed the button did not report the fault, the collector did.
     */
    private function systemReporter(): ?User
    {
        return User::query()->active()->where('role', 'admin')->orderBy('id')->first();
    }
}
