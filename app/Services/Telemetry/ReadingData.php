<?php

namespace App\Services\Telemetry;

use App\Models\AccessPoint;
use Carbon\CarbonInterface;

/**
 * One measurement, on its way from a driver into the database.
 *
 * A plain readonly object rather than an array or an Eloquent model, so a
 * driver cannot quietly hand back a differently-shaped payload and so nothing
 * touches the database until TelemetryService decides to store it.
 *
 * `status` is deliberately absent. A driver reports what it MEASURED;
 * classifying that into online / degraded / offline is TelemetryService's job
 * and needs the previous readings, which a driver does not have. A driver that
 * could set status would let the simulator decide its own health.
 *
 * @see docs/decisions/ADR-0002-telemetry-source-driver.md
 */
final readonly class ReadingData
{
    public function __construct(
        public int $deviceId,
        public CarbonInterface $recordedAt,
        public ?int $latencyMs,
        public int $clientCount,
        public float $packetLossPct,
        public float $channelUtilPct,
        public float $throughputMbps,
        public string $source,
        /**
         * True when the access point did not answer at all. Distinct from a
         * reading with bad numbers — an unreachable AP has no numbers, and
         * storing zeros for it would drag every average down and make an
         * outage look like excellent latency.
         */
        public bool $unreachable = false,
        /**
         * From an agent (ADR-0017): which one, its number for this reading,
         * and when Atlas received it. Null for readings made inside Atlas.
         * The agent and sequence make a retried reading a duplicate.
         */
        public ?int $agentId = null,
        public ?int $sequence = null,
        public ?CarbonInterface $receivedAt = null,
    ) {}

    /** An access point that did not respond. */
    public static function unreachable(AccessPoint $ap, CarbonInterface $at, string $source): self
    {
        return new self(
            deviceId: $ap->id,
            recordedAt: $at,
            latencyMs: null,
            clientCount: 0,
            packetLossPct: 100.0,
            channelUtilPct: 0.0,
            throughputMbps: 0.0,
            source: $source,
            unreachable: true,
        );
    }

    /** @return array<string, mixed> the row shape, for a chunked insert */
    public function toRow(string $status): array
    {
        return [
            'network_device_id' => $this->deviceId,
            'recorded_at' => $this->recordedAt->toDateTimeString(),
            'latency_ms' => $this->latencyMs,
            'client_count' => $this->clientCount,
            'packet_loss_pct' => round($this->packetLossPct, 2),
            'channel_util_pct' => round($this->channelUtilPct, 2),
            'throughput_mbps' => round($this->throughputMbps, 2),
            'status' => $status,
            'source' => $this->source,
            'agent_id' => $this->agentId,
            'sequence' => $this->sequence,
            'received_at' => $this->receivedAt?->toDateTimeString(),
        ];
    }
}
