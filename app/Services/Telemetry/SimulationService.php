<?php

namespace App\Services\Telemetry;

use App\Enums\NetworkStatus;
use App\Enums\SimulationScenario;
use App\Models\AccessPoint;
use App\Models\NetworkDevice;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The Network monitor's simulation controls: take a sample on demand, and force
 * a scenario onto the simulated network.
 *
 * WHAT A SCENARIO IS NOT. It never sets a status. It changes what the
 * simulator reports, and TelemetryService classifies those readings by
 * the ordinary rules, so the rest of the system reacts exactly as it would to
 * the real thing:
 *
 *   congestion         load over capacity. Degraded after `consecutive_breach`
 *                      breaching readings in a row, not on the first.
 *   outage             unreachable. Offline on the next reading, one ticket,
 *                      one alarm to the engineers.
 *   feed interruption  the simulator stops measuring it. Unknown, not offline —
 *                      the silence sweep only judges what somebody was listening to.
 *   recovery (null)    back to the normal model.
 *
 * Both actions take a collection round immediately, the same TelemetryCollector
 * round the scheduler runs, so an outage or a recovery shows at once.
 *
 * Only for devices the simulator watches. On one a real agent measures, the
 * controls would be forcing fiction onto a real device, so they are refused,
 * and with nothing simulated they don't appear at all.
 *
 * @see SimulationScenario
 * @see docs/modules/M5-telemetry-and-dashboard.md
 */
class SimulationService
{
    public function __construct(
        private readonly Simulator $simulator,
        private readonly TelemetryCollector $collector,
    ) {}

    /** Whether the controls exist at all: only while the simulator watches something. */
    public function available(): bool
    {
        return $this->simulator->watched()->exists();
    }

    /**
     * The access points a scenario can be forced onto, by id: the simulator's.
     * The control room offers only these, so it never offers a fault it would
     * then refuse.
     *
     * @return list<int>
     */
    public function targets(): array
    {
        return AccessPoint::query()->simulated()->pluck('id')->all();
    }

    /**
     * One collection round, now, instead of waiting for the scheduler.
     *
     * @return Collection<int, array{device: NetworkDevice, from: NetworkStatus, to: NetworkStatus}>
     */
    public function sample(): Collection
    {
        $this->ensureAvailable();

        return $this->collector->tick();
    }

    /**
     * Force a scenario onto one access point, or every one the simulator
     * measures.
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

        if ($target !== null && ! $target->agent?->is_virtual) {
            throw new DomainException(
                "{$target->code} is measured by a real agent, so it can't be given a simulated fault."
            );
        }

        if ($target?->status === NetworkStatus::Maintenance) {
            throw new DomainException(
                "{$target->code} is under maintenance, so the collector does not poll it. "
                .'Take it out of maintenance first.'
            );
        }

        $accessPoints = match (true) {
            $target !== null => collect([$target]),
            $scenario === null => AccessPoint::query()->whereNotNull('simulation_scenario')->get(),
            default => AccessPoint::query()->simulated()->collectable()->get(),
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
                'Simulation controls need devices the simulator watches. '
                .'Every device here is measured by a real agent.'
            );
        }
    }
}
