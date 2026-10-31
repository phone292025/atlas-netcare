<?php

namespace App\Services\Telemetry;

use App\Models\Agent;
use App\Models\NetworkDevice;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * One batch of readings from a probe agent (ADR-0017, roadmap task 1.3).
 *
 * The request has already checked the batch's shape. This decides what
 * happens to each reading: stored, a duplicate of one stored before, or
 * refused, with a reason the agent can log. The agent then drops every
 * sequence it was told about, so nothing it sent waits in its buffer for ever.
 *
 * Stored readings go through the same path as the simulator's, so a reading
 * from an agent changes a device's status and raises tickets and alerts
 * exactly as a simulated one does.
 */
class ReadingIngest
{
    /** One reason for an unknown device and another agent's, so a token can't list device codes. */
    public const NOT_WATCHED = 'Not a device this agent watches.';

    public function __construct(
        private readonly TelemetryService $telemetry,
        private readonly TelemetryCollector $collector,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $readings  validated by IngestReadingsRequest
     * @return array{stored: list<int>, duplicate: list<int>, rejected: list<array{sequence: int, reason: string}>}
     */
    public function ingest(Agent $agent, array $readings, CarbonInterface $receivedAt): array
    {
        $devices = $agent->devices()->get()->keyBy(fn (NetworkDevice $device) => $device->code);

        $earliest = $receivedAt->copy()->subDays((int) config('atlas.retention.late_reading_days'));
        $latest = $receivedAt->copy()->addMinutes((int) config('atlas.ingest.future_minutes'));

        $accepted = collect();
        $rejected = [];

        foreach ($readings as $reading) {
            $sequence = (int) $reading['sequence'];
            $device = $devices->get(strtoupper(trim((string) $reading['device'])));
            $measuredAt = Carbon::parse($reading['measured_at'])->utc();

            $reason = match (true) {
                $device === null => self::NOT_WATCHED,
                $measuredAt->gt($latest) => "Measured more than {$this->minutes()} minutes in the future. Check the agent's clock.",
                $measuredAt->lt($earliest) => 'Measured more than '.config('atlas.retention.late_reading_days').' days ago, too late to store.',
                default => null,
            };

            if ($reason !== null) {
                $rejected[] = ['sequence' => $sequence, 'reason' => $reason];

                continue;
            }

            $accepted->push($this->toReading($agent, $device, $reading, $measuredAt, $receivedAt));
        }

        // One transaction: if the ticket or the alert fails, the readings go
        // too, and the agent's retry raises them. Stored alone, the retry was
        // all duplicates, the device was already Offline, and the outage was
        // never ticketed.
        $result = DB::transaction(function () use ($accepted): StoredReadings {
            $result = $this->telemetry->record($accepted);
            $this->collector->react($result->transitions);

            return $result;
        });

        $stored = $result->stored->map(fn (ReadingData $r) => $r->sequence)->values()->all();

        return [
            'stored' => $stored,
            'duplicate' => $accepted->map(fn (ReadingData $r) => $r->sequence)->diff($stored)->values()->all(),
            'rejected' => $rejected,
        ];
    }

    /** @param  array<string, mixed>  $reading */
    private function toReading(Agent $agent, NetworkDevice $device, array $reading, CarbonInterface $measuredAt, CarbonInterface $receivedAt): ReadingData
    {
        $reachable = (bool) $reading['reachable'];

        // A device that didn't answer has no numbers: zeros would drag every
        // average down and make an outage look like excellent latency.
        return new ReadingData(
            deviceId: $device->id,
            recordedAt: $measuredAt,
            latencyMs: $reachable && isset($reading['latency_ms']) ? (int) round((float) $reading['latency_ms']) : null,
            clientCount: $reachable ? (int) ($reading['client_count'] ?? 0) : 0,
            packetLossPct: $reachable ? (float) ($reading['packet_loss_pct'] ?? 0) : 100.0,
            channelUtilPct: $reachable ? (float) ($reading['channel_util_pct'] ?? 0) : 0.0,
            throughputMbps: $reachable ? (float) ($reading['throughput_mbps'] ?? 0) : 0.0,
            source: 'live',
            unreachable: ! $reachable,
            agentId: $agent->id,
            sequence: (int) $reading['sequence'],
            receivedAt: $receivedAt,
        );
    }

    private function minutes(): int
    {
        return (int) config('atlas.ingest.future_minutes');
    }
}
