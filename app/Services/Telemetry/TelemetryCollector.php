<?php

namespace App\Services\Telemetry;

use App\Enums\NetworkStatus;
use App\Events\AgentSilenceChanged;
use App\Events\DeviceStatusesChanged;
use App\Models\NetworkDevice;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * One collection round, and the news of what it found.
 *
 * Fill any gap since the last round, take the simulator's readings for the
 * devices it watches, sweep the silent ones, then publish the status changes
 * and any agent that has gone silent (task 1.6). What happens next, the
 * incidents, tickets and alerts, belongs to whoever listens (ADR-0014,
 * roadmap 2.1): this module imports nothing from Tickets or Shifts, and an
 * architecture test keeps it that way.
 *
 * The scheduled command and the Network monitor's "Generate sample" button
 * both run this. Real agents send their readings to the ingest API instead,
 * and come to react() from there.
 *
 * @see App\Console\Commands\CollectTelemetry
 * @see App\Listeners\OpenAndResolveIncidents
 * @see docs/modules/M5-telemetry-and-dashboard.md
 */
class TelemetryCollector
{
    public function __construct(
        private readonly TelemetryService $telemetry,
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
        // The event goes out once this commits.
        $transitions = DB::transaction(function () use ($report): Collection {
            $transitions = $this->simulator->round()->transitions
                ->concat($this->telemetry->markStaleOffline())
                ->concat($this->telemetry->markUnknown());

            $this->react($transitions, $report);

            return $transitions;
        });

        // Claimed last. The incident sweep opens the incident for a silence
        // whose event is lost, from `silent_since`.
        DB::transaction(function () use ($report): void {
            foreach ($this->agents->noticeSilence() as $agent) {
                AgentSilenceChanged::dispatch($agent->id, true);
                $report('warn', "{$agent->name} has stopped reporting.");
            }
        });

        return $transitions;
    }

    /**
     * Publish status changes, one event for the batch. The scheduler's round
     * and the ingest API both come here, so a reading from an agent leads to
     * exactly what a simulated one does.
     *
     * @param  Collection<int, array{device: NetworkDevice, from: NetworkStatus, to: NetworkStatus}>  $transitions
     * @param  (Closure(string, string): void)|null  $report
     */
    public function react(Collection $transitions, ?Closure $report = null): void
    {
        $report ??= static function (string $level, string $message): void {};

        foreach ($transitions as $change) {
            $report(
                $change['to'] === NetworkStatus::Online ? 'info' : 'warn',
                "{$change['device']->code}: {$change['from']->label()} → {$change['to']->label()}",
            );
        }

        if ($transitions->isNotEmpty()) {
            event(DeviceStatusesChanged::fromTransitions($transitions));
        }
    }
}
