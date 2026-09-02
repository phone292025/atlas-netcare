<?php

namespace App\Console\Commands;

use App\Enums\AlarmType;
use App\Enums\ApStatus;
use App\Enums\TicketCategory;
use App\Enums\TicketPriority;
use App\Enums\UserRole;
use App\Models\User;
use App\Services\Shifts\AlarmService;
use App\Services\Telemetry\TelemetryService;
use App\Services\Tickets\TicketService;
use Illuminate\Console\Command;

/**
 * Take one reading from every access point and store it.
 *
 * Runs every minute on the schedule. Enters at the SERVICE layer and skips
 * controllers entirely, which is the point of putting the rules there — this
 * command and a browser request share exactly the same logic.
 *
 * @see docs/modules/M5-telemetry-and-dashboard.md
 * @see docs/05-architecture.md
 */
class CollectTelemetry extends Command
{
    protected $signature = 'atlas:collect-telemetry {--quiet-summary : Print only the counts}';

    protected $description = 'Poll every access point once and store the readings';

    public function handle(TelemetryService $telemetry, TicketService $tickets, AlarmService $alarms): int
    {
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
        $transitions = $telemetry->collectAndStore()
            ->concat($telemetry->markStaleOffline());

        foreach ($transitions as $change) {
            $this->reportTransition($change['access_point'], $change['from'], $change['to']);

            if ($change['to'] === ApStatus::Offline) {
                $this->raiseOfflineTicket($tickets, $change['access_point']);

                /*
                 * And tell the engineers directly (FR-506). The ticket is the
                 * record of work; the alarm is what puts it in front of
                 * somebody. Raised through AlarmService, so M5 knows nothing
                 * about how an alarm is stored, scheduled or delivered.
                 *
                 * On the transition only, exactly like the ticket — otherwise
                 * a six-hour outage rings the bell 360 times.
                 */
                $alarms->raiseForRole(
                    AlarmType::ApOffline,
                    UserRole::Engineer,
                    "{$change['access_point']->ap_code} is not responding",
                    $change['access_point']->location?->label(),
                    related: $change['access_point'],
                );
            }
        }

        $this->info('Telemetry collected. '.$transitions->count().' status '
            .str('change')->plural($transitions->count()).'.');

        return self::SUCCESS;
    }

    /**
     * An access point going offline raises exactly one ticket (FR-503).
     *
     * Two guards, both needed. This runs only on the TRANSITION into offline,
     * not on every offline reading — otherwise a six-hour outage files 360
     * tickets. And `hasOutstandingFor()` covers the case where somebody has
     * already reported it by hand, or where the AP flaps in and out and the
     * first ticket is still open.
     */
    private function raiseOfflineTicket(TicketService $tickets, $ap): void
    {
        if ($tickets->hasOutstandingFor($ap)) {
            return;
        }

        $reporter = $this->systemReporter();

        if (! $reporter) {
            // Nobody to attribute it to. Say so rather than failing the whole
            // collection run — the reading itself is already safely stored.
            $this->warn("No administrator to attribute the auto-ticket for {$ap->ap_code}.");

            return;
        }

        $tickets->open([
            'title' => "{$ap->ap_code} is not responding",
            'description' => "Raised automatically: no response from {$ap->ap_code}"
                .($ap->location ? " in {$ap->location->label()}" : '')
                .'. Last seen '.($ap->last_seen_at?->diffForHumans() ?? 'never').'.',
            'category' => TicketCategory::Network->value,
            'priority' => TicketPriority::Critical->value,
            'location_id' => $ap->location_id,
        ], $reporter, $ap);

        $this->warn("Raised a ticket for {$ap->ap_code}.");
    }

    /**
     * Who an automatic ticket is attributed to.
     *
     * `reported_by` is not nullable — a ticket with no reporter is a ticket
     * nobody can be asked about. The first administrator stands in for the
     * system, which is honest: an administrator configured the collector.
     */
    private function systemReporter(): ?User
    {
        return User::query()->active()->where('role', 'admin')->orderBy('id')->first();
    }

    private function reportTransition($ap, ApStatus $from, ApStatus $to): void
    {
        $line = "{$ap->ap_code}: {$from->label()} → {$to->label()}";

        $to === ApStatus::Online ? $this->info($line) : $this->warn($line);
    }
}
