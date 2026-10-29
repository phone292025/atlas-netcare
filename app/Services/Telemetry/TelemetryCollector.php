<?php

namespace App\Services\Telemetry;

use App\Enums\AlarmType;
use App\Enums\NetworkStatus;
use App\Enums\TicketCategory;
use App\Enums\TicketPriority;
use App\Enums\UserRole;
use App\Models\Agent;
use App\Models\NetworkDevice;
use App\Models\User;
use App\Services\Shifts\AlarmService;
use App\Services\Tickets\TicketService;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * One collection round, and everything the system does about what it finds.
 *
 * Fill any gap since the last round, take the simulator's readings for the
 * devices it watches, sweep the silent ones, then act on each status change.
 * A probe agent that has stopped reporting gets one alert, and its devices
 * turn Unknown (task 1.6).
 * The scheduled command and the Network monitor's "Generate sample" button
 * both run this, so both raise the same tickets and alerts. Real agents send
 * their readings to the ingest API instead, and come to react() from there.
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
        private readonly Simulator $simulator,
        private readonly AgentService $agents,
    ) {}

    /**
     * Run one round. `$report` receives each line worth telling a person, as a
     * level (`info` or `warn`) and a message; the command prints them.
     *
     * @param  (Closure(string, string): void)|null  $report
     * @return Collection<int, array{device: NetworkDevice, from: NetworkStatus, to: NetworkStatus}>
     */
    public function tick(?Closure $report = null): Collection
    {
        $report ??= static function (string $level, string $message): void {};

        /*
         * The scheduler was off. Without this the charts came back to a single
         * point and an empty day, and the only way out was a backfill by hand.
         * Before the live reading, so classification sees the filled history.
         * Simulated devices only: silence from real kit is a fact.
         */
        if ($gap = $this->backfill->fillGap()) {
            $report('info', 'Filled the gap since '.$gap['from']->campus()->format('j M, H:i')
                .' with '.number_format($gap['written']).' simulated readings.');
        }

        // The simulator's readings, plus devices nobody heard from. The sweep
        // covers every agent's devices, not only the simulator's: an agent
        // still sending heartbeats can stop sending one device's readings.
        // An agent that has stopped altogether leaves its devices Unknown.
        $transitions = $this->simulator->round()->transitions
            ->concat($this->telemetry->markStaleOffline())
            ->concat($this->telemetry->markUnknown());

        $this->react($transitions, $report);

        // Claimed last, and with its alert in one transaction: a round that
        // fails before the alerts are stored leaves the silence unclaimed,
        // so the next round still alerts.
        DB::transaction(function () use ($report): void {
            foreach ($this->agents->noticeSilence() as $agent) {
                $this->alertSilentAgent($agent, $report);
            }
        });

        return $transitions;
    }

    /**
     * Act on status changes: a ticket and an alert when a device goes
     * offline, an alert when one degrades. The scheduler's round and the
     * ingest API both come here, so a reading from an agent raises exactly
     * what a simulated one does.
     *
     * @param  Collection<int, array{device: NetworkDevice, from: NetworkStatus, to: NetworkStatus}>  $transitions
     * @param  (Closure(string, string): void)|null  $report
     */
    public function react(Collection $transitions, ?Closure $report = null): void
    {
        $report ??= static function (string $level, string $message): void {};

        foreach ($transitions as $change) {
            $ap = $change['device'];
            $line = "{$ap->code}: {$change['from']->label()} → {$change['to']->label()}";
            $report($change['to'] === NetworkStatus::Online ? 'info' : 'warn', $line);

            /*
             * Engineers are alerted on the transition only, never on every
             * reading, or a six-hour outage rings the bell 360 times (FR-305).
             *
             * Degraded alerts only from Online, or from Unknown, which
             * nobody was told the truth behind. An access point coming back
             * from an outage passes through Degraded, because its unreachable
             * readings count as 100% packet loss; that is recovery, not a new
             * anomaly. And at most once per cooldown per access point: at peak
             * one hovering near a threshold flaps every few minutes.
             */
            if ($change['to'] === NetworkStatus::Offline) {
                $this->raiseOfflineTicket($ap, $report);
                $this->alertEngineers(AlarmType::ApOffline, $ap, "{$ap->code} is not responding", $ap->location?->label());
            } elseif ($change['to'] === NetworkStatus::Degraded
                && in_array($change['from'], [NetworkStatus::Online, NetworkStatus::Unknown], true)
                && ! $this->alarms->raisedRecently(AlarmType::ApDegraded, $ap, (int) config('atlas.thresholds.degraded_alert_cooldown_min'))) {
                $why = 'Latency, packet loss or load has been over its threshold for '
                    .config('atlas.thresholds.consecutive_breach').' readings in a row.';

                $this->alertEngineers(
                    AlarmType::ApDegraded,
                    $ap,
                    "{$ap->code} is degraded",
                    $ap->location ? "{$ap->location->label()}. {$why}" : $why,
                );
            }
        }
    }

    /**
     * One alert per silence, to engineers and administrators: engineers look
     * after the network it measures, and administrators look after agents.
     *
     * @param  Closure(string, string): void  $report
     */
    private function alertSilentAgent(Agent $agent, Closure $report): void
    {
        $title = "{$agent->name} has stopped reporting";
        $message = 'Last heard from at '.$agent->last_seen_at->campus()->format('j M, H:i')
            .'. Its devices show as unknown until it reports again.';

        foreach ([UserRole::Engineer, UserRole::Admin] as $role) {
            $this->alarms->raiseForRole(AlarmType::AgentSilent, $role, $title, $message, related: $agent);
        }

        $report('warn', "{$title}.");
    }

    /**
     * Through AlarmService, so this module knows nothing about how an alarm is
     * stored, scheduled or delivered. The ticket is the record of work; the
     * alarm is what puts it in front of somebody.
     */
    private function alertEngineers(AlarmType $type, NetworkDevice $ap, string $title, ?string $message): void
    {
        $this->alarms->raiseForRole($type, UserRole::Engineer, $title, $message, related: $ap);
    }

    /**
     * A device going offline raises exactly one ticket (FR-503): only on the
     * transition, and not while an earlier ticket about it is still open,
     * whether raised by hand or by a previous flap.
     *
     * @param  Closure(string, string): void  $report
     */
    private function raiseOfflineTicket(NetworkDevice $ap, Closure $report): void
    {
        if ($this->tickets->hasOutstandingFor($ap)) {
            return;
        }

        $reporter = $this->systemReporter();

        if (! $reporter) {
            // Say so rather than failing the round: the reading is stored.
            $report('warn', "No administrator to attribute the auto-ticket for {$ap->code}.");

            return;
        }

        // An absolute campus time, because the description is saved: "2
        // hours ago" would still say so next week.
        $this->tickets->open([
            'title' => "{$ap->code} is not responding",
            'description' => "Raised automatically: no response from {$ap->code}"
                .($ap->location ? " in {$ap->location->label()}" : '')
                .'. Last seen '.($ap->last_seen_at?->campus()->format('j M Y, H:i') ?? 'never').'.',
            'category' => TicketCategory::Network->value,
            'priority' => TicketPriority::Critical->value,
            'location_id' => $ap->location_id,
        ], $reporter, $ap, isDemo: (bool) $ap->is_demo);

        $report('warn', "Raised a ticket for {$ap->code}.");
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
