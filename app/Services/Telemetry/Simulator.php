<?php

namespace App\Services\Telemetry;

use App\Enums\SimulationScenario;
use App\Models\Agent;
use App\Models\NetworkDevice;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Atlas's virtual agent: the simulator, sending readings from inside Atlas
 * (ADR-0010, roadmap task 1.5).
 *
 * It watches devices like any agent does, through `network_devices.agent_id`,
 * and its readings take the same path into the database as a real agent's:
 * TelemetryService::record(), then the same reactions. So the demonstration
 * and a real network share one way in, and a test of one tests the other.
 *
 * Its readings are numbered by the second they were measured at and the
 * device, so a backfill run twice stores nothing new: the unique key on
 * agent, sequence and time sees them as duplicates. Not by the minute: a
 * scenario forced half way through a minute has to show in the round that
 * forcing it takes.
 *
 * @see App\Services\Telemetry\SimulatedReadings
 */
class Simulator
{
    public function __construct(
        private readonly SimulatedReadings $readings,
        private readonly TelemetryService $telemetry,
    ) {}

    /**
     * The devices it measures: the ones it watches, except those under
     * maintenance, and those whose feed is simulated as interrupted. There
     * the path to the device is what failed, so nobody is listening, and the
     * silence sweep must not turn "we cannot see it" into "it is down".
     *
     * @return Builder<NetworkDevice>
     */
    public function watched(): Builder
    {
        return NetworkDevice::query()
            ->simulated()
            ->collectable()
            ->where(fn (Builder $query) => $query
                ->whereNull('simulation_scenario')
                ->orWhere('simulation_scenario', '!=', SimulationScenario::FeedInterruption->value));
    }

    /**
     * One reading for each device, at one moment, numbered for the agent
     * that watches it.
     *
     * @param  Collection<int, NetworkDevice>  $devices
     * @return Collection<int, ReadingData>
     */
    public function readingsAt(Collection $devices, CarbonInterface $at): Collection
    {
        $moment = Carbon::instance($at->toDateTime())->startOfSecond();

        return $devices->map(function (NetworkDevice $device) use ($moment): ReadingData {
            $reading = $this->readings->reading($device, $moment);

            return new ReadingData(
                deviceId: $reading->deviceId,
                recordedAt: $reading->recordedAt,
                latencyMs: $reading->latencyMs,
                clientCount: $reading->clientCount,
                packetLossPct: $reading->packetLossPct,
                channelUtilPct: $reading->channelUtilPct,
                throughputMbps: $reading->throughputMbps,
                source: SimulatedReadings::SOURCE,
                unreachable: $reading->unreachable,
                agentId: $device->agent_id,
                sequence: self::sequence($device->id, $moment),
                receivedAt: now(),
            );
        })->values();
    }

    /**
     * One round, now: a reading for every watched device, stored. The agent
     * is marked as heard from only when it measured something.
     */
    public function round(): StoredReadings
    {
        $devices = $this->watched()->get();
        $result = $this->telemetry->record($this->readingsAt($devices, now()));

        if ($devices->isNotEmpty()) {
            // A query, not save(): contact is not worth an audit row.
            Agent::query()->where('is_virtual', true)->update(['last_seen_at' => now()]);
        }

        return $result;
    }

    /**
     * A device's sequence number for one moment: the same every time that
     * second is measured, and different for every other second and device.
     * Below 2^53 until the year 2255 with device ids under a million, so an
     * agent written in any language reads it exactly.
     */
    public static function sequence(int $deviceId, CarbonInterface $at): int
    {
        return $at->getTimestamp() * 1_000_000 + $deviceId;
    }
}
