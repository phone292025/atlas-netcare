<?php

namespace App\Services\Telemetry;

use App\Models\Agent;
use App\Models\NetworkDevice;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * One batch of readings from a probe agent (ADR-0017, roadmap task 1.3).
 *
 * The request has already checked the batch's shape. This decides what
 * happens to each reading: stored, a duplicate of one stored before, or
 * refused, with a reason the agent can log. The agent then drops every
 * sequence it was told about, so nothing it sent waits in its buffer for ever.
 *
 * Stored readings go through the same path as the simulator's, so a reading
 * from an agent changes a device's status, and publishes the change for the
 * incidents to follow, exactly as a simulated one does.
 */
class ReadingIngest
{
    /** One reason for an unknown device and another agent's, so a token can't list device codes. */
    public const NOT_WATCHED = 'Not a device this agent watches.';

    /**
     * One reading's values. Every number has a range, so nothing an agent
     * sends can overflow a column or claim a loss of 300 per cent. A reading
     * that breaks these is rejected on its own (IngestReadingsRequest says
     * why not the whole batch).
     *
     * @var array<string, list<string>>
     */
    private const READING_RULES = [
        'device' => ['required', 'string', 'max:40'],
        'measured_at' => ['required', 'date'],
        'reachable' => ['required', 'boolean'],
        // smallint on PostgreSQL, which stops at 32767 whatever "unsigned" says.
        'latency_ms' => ['nullable', 'numeric', 'min:0', 'max:32767'],
        'packet_loss_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
        'client_count' => ['nullable', 'integer', 'min:0', 'max:32767'],
        'channel_util_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
        'throughput_mbps' => ['nullable', 'numeric', 'min:0', 'max:999999'],
    ];

    private const FIELDS = ['sequence', 'device', 'measured_at', 'reachable', 'latency_ms', 'packet_loss_pct', 'client_count', 'channel_util_pct', 'throughput_mbps'];

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

            if ($invalid = $this->invalid($reading)) {
                $rejected[] = ['sequence' => $sequence, 'reason' => $invalid];

                continue;
            }

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

        // The readings and the statuses they move commit together. The status
        // changes are published once this commits; incidents, tickets and
        // alerts follow on the queue, and the incident sweep catches any the
        // queue loses, from the statuses stored here.
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

    /**
     * Why this reading's values can't be stored, or null.
     *
     * @param  array<string, mixed>  $reading
     */
    private function invalid(array $reading): ?string
    {
        if ($unknown = array_diff(array_keys($reading), self::FIELDS)) {
            return 'Unknown field: '.implode(', ', $unknown).'.';
        }

        $validator = Validator::make($reading, self::READING_RULES);

        return $validator->fails() ? $validator->errors()->first() : null;
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
