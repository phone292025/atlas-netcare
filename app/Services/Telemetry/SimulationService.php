<?php

namespace App\Services\Telemetry;

use App\Enums\NetworkStatus;
use App\Enums\SimulationScenario;
use App\Models\AccessPoint;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The Network monitor's simulation controls: take a sample on demand, and force
 * a scenario onto the simulated network.
 *
 * WHAT A SCENARIO IS NOT. It never sets a status. It changes what the
 * simulated driver reports, and TelemetryService classifies those readings by
 * the ordinary rules, so the rest of the system reacts exactly as it would to
 * the real thing:
 *
 *   congestion         load over capacity. Degraded after `consecutive_breach`
 *                      breaching readings in a row, not on the first.
 *   outage             unreachable. Offline on the next reading, one ticket,
 *                      one alarm to the engineers.
 *   feed interruption  the driver stops polling it. Unknown, not offline — the
 *                      silence sweep only judges what somebody was listening to.
 *   recovery (null)    back to the normal model.
 *
 * Both actions take a collection round immediately, the same TelemetryCollector
 * round the scheduler runs, so an outage or a recovery shows at once.
 *
 * Refused outright unless the simulated driver is active. Against a live
 * network the controls would be forcing fiction onto real devices, and the
 * SNMP driver throws on collect() by design.
 *
 * @see SimulationScenario
 * @see docs/modules/M5-telemetry-and-dashboard.md
 */
class SimulationService
{
    public function __construct(
        private readonly TelemetrySource $source,
        private readonly TelemetryCollector $collector,
    ) {}

    /** Whether the controls exist at all: only against the simulated driver. */
    public function available(): bool
    {
        return $this->source instanceof SimulatedTelemetrySource;
    }

    /**
     * One collection round, now, instead of waiting for the scheduler.
     *
     * @return Collection<int, array{access_point: AccessPoint, from: NetworkStatus, to: NetworkStatus}>
     */
    public function sample(): Collection
    {
        $this->ensureAvailable();

        return $this->collector->tick();
    }

    /**
     * Force a scenario onto one access point, or every one being polled.
     *
     * `null` is Recovery. Recovering "all" clears every scenario in the
     * estate, including on access points that have since gone into
     * maintenance, because a leftover scenario would surprise whoever brings
     * one back.
     *
     * Returns how many access points it applied to.
     */
    public function apply(?SimulationScenario $scenario, ?AccessPoint $target = null): int
    {
        $this->ensureAvailable();

        if ($target?->status === NetworkStatus::Maintenance) {
            throw new DomainException(
                "{$target->ap_code} is under maintenance, so the collector does not poll it. "
                .'Take it out of maintenance first.'
            );
        }

        $accessPoints = match (true) {
            $target !== null => collect([$target]),
            $scenario === null => AccessPoint::query()->whereNotNull('simulation_scenario')->get(),
            default => AccessPoint::query()->collectable()->get(),
        };

        DB::transaction(function () use ($accessPoints, $scenario): void {
            foreach ($accessPoints as $ap) {
                // forceFill: both columns are system-written and deliberately
                // absent from $fillable. The audit observer records who did it.
                $ap->forceFill([
                    'simulation_scenario' => $scenario,
                    'simulation_started_at' => $scenario ? now() : null,
                ])->save();
            }
        });

        $this->collector->tick();

        return $accessPoints->count();
    }

    private function ensureAvailable(): void
    {
        if (! $this->available()) {
            throw new DomainException(
                'Simulation controls only work with the simulated telemetry driver. '
                .'This installation reads a real network.'
            );
        }
    }
}
