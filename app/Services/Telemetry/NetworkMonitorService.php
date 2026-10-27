<?php

namespace App\Services\Telemetry;

use App\Enums\NetworkStatus;
use App\Models\AccessPoint;
use App\Models\NetworkDevice;
use App\Models\TelemetryReading;
use App\Support\CampusClock;
use App\Support\Tone;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * What the Network monitor shows: every access point's state right now, and
 * campus activity over the last day.
 *
 * One method serves the first render and every poll, for the same reason
 * TelemetryService::dashboardData() does — the two cannot disagree.
 *
 * STALE IS A DISPLAY STATE, NOT A STATUS. An access point whose newest reading
 * is older than `offline_after_min` is shown as unknown rather than as its last
 * recorded status. Nothing is written: the stored status stays what the
 * classifier last concluded. This is how a stopped collector, or a simulated
 * feed interruption, reads as "we do not know" instead of a confident Online
 * from two weeks ago. Offline and maintenance are shown as they are — the
 * system has already decided those, and a ticket may hang on it.
 *
 * @see docs/modules/M5-telemetry-and-dashboard.md
 */
class NetworkMonitorService
{
    /**
     * Leading characters of a stored timestamp that make one chart point:
     * `2026-09-19 14:3` is ten minutes, which keeps 24 hours at 144 points.
     */
    private const BUCKET_CHARS = 15;

    public function __construct(private readonly TelemetryService $telemetry) {}

    /**
     * Where the numbers come from: `simulated`, `live` from probe agents on a
     * real network, or `mixed` while one is being brought in beside the
     * other.
     */
    public function feed(): string
    {
        $simulated = NetworkDevice::query()->simulated()->exists();
        $live = NetworkDevice::query()->whereHas('agent', fn ($agent) => $agent->where('is_virtual', false))->exists();

        return match (true) {
            $simulated && $live => 'mixed',
            $simulated => 'simulated',
            default => 'live',
        };
    }

    /** @return array<string, mixed> */
    public function snapshot(): array
    {
        $accessPoints = AccessPoint::query()
            ->with('location')
            ->orderBy('code')
            ->get();

        $latest = $this->telemetry->latestReadings($accessPoints->pluck('id'));
        $rows = $accessPoints->map(fn (AccessPoint $ap) => $this->row($ap, $latest->get($ap->id)))->values();
        $newest = $latest->max('recorded_at');

        return [
            'generated_at' => now()->toIso8601String(),
            'last_sample_at' => $newest?->toIso8601String(),
            'last_sample' => $newest ? $this->campusTime($newest) : null,
            'counts' => [
                'total' => $rows->count(),
                'online' => $rows->where('state', 'online')->count(),
                'degraded' => $rows->where('state', 'degraded')->count(),
                'offline' => $rows->where('state', 'offline')->count(),
                'maintenance' => $rows->where('state', 'maintenance')->count(),
                'stale' => $rows->where('state', 'stale')->count(),
            ],
            'clients_now' => (int) $rows->sum('clients'),
            'access_points' => $rows->all(),
            'activity' => $this->activity($accessPoints->pluck('id')),
        ];
    }

    /**
     * One access point, for the detail dialog.
     *
     * @return array<string, mixed>
     */
    public function accessPoint(AccessPoint $accessPoint): array
    {
        $accessPoint->loadMissing('location');
        $reading = $this->telemetry->latestReadings(collect([$accessPoint->id]))->get($accessPoint->id);

        return [
            ...$this->row($accessPoint, $reading),
            'model' => $accessPoint->model,
            'ip_address' => $accessPoint->ip_address,
            'activity' => $this->activity(collect([$accessPoint->id]), withLatency: true),
        ];
    }

    /** @return array<string, mixed> */
    private function row(AccessPoint $ap, ?TelemetryReading $reading): array
    {
        $state = $this->state($ap, $reading);
        $current = in_array($state, ['online', 'degraded'], true);

        [$label, $tone] = $state === 'stale'
            ? ['Unknown', Tone::NEUTRAL]
            : [$ap->status->label(), $ap->status->tone()];

        $scenario = $ap->simulation_scenario;

        return [
            'id' => $ap->id,
            'code' => $ap->code,
            'location' => $ap->location?->code,
            'location_name' => $ap->location?->name,
            'location_id' => $ap->location_id,
            'state' => $state,
            'state_label' => $label,
            'badge' => Tone::badge($tone),
            'accent' => Tone::accent($tone),
            'fill' => Tone::solid($tone),
            // Numbers are only shown when they describe now. A two-week-old
            // client count beside "Unknown" would contradict it.
            'clients' => $current ? $reading?->client_count : null,
            'capacity' => $ap->client_capacity,
            // Clamped for the bar: readings the simulator wrote before
            // 2026-09-19 can hold a negative utilisation, and a bar cannot.
            'utilization' => $current && $reading?->channel_util_pct !== null
                ? (int) max(0, min(100, round((float) $reading->channel_util_pct)))
                : null,
            'latency_ms' => $current ? $reading?->latency_ms : null,
            'last_seen' => $ap->last_seen_at ? $this->campusTime($ap->last_seen_at) : null,
            'scenario' => $scenario?->value,
            'scenario_label' => $scenario?->label(),
            'scenario_badge' => $scenario ? Tone::badge($scenario->tone()) : null,
        ];
    }

    private function state(AccessPoint $ap, ?TelemetryReading $reading): string
    {
        if (in_array($ap->status, [NetworkStatus::Maintenance, NetworkStatus::Offline], true)) {
            return $ap->status->value;
        }

        $window = now()->subMinutes((int) config('atlas.thresholds.offline_after_min'));

        return $reading === null || $reading->recorded_at->lt($window) ? 'stale' : $ap->status->value;
    }

    /**
     * Connected clients and throughput across these access points, in ten-minute
     * points over the last 24 hours.
     *
     * Grouped in SQL by the first fifteen characters of the timestamp as text
     * — `2026-09-19 14:3` — which is a ten-minute bucket. SQLite and MariaDB
     * can cut the stored value directly. PostgreSQL stores a real timestamp,
     * so it formats it with `to_char` first. Averaged per access
     * point within a bucket, then summed across access points, so a bucket
     * that happens to hold eleven collection rounds does not count clients
     * eleven times. At most 144 rows per access point leave the database.
     *
     * @param  Collection<int, int>  $accessPointIds
     * @return list<array<string, mixed>>
     */
    private function activity(Collection $accessPointIds, bool $withLatency = false): array
    {
        if ($accessPointIds->isEmpty()) {
            return [];
        }

        $bucket = DB::connection()->getDriverName() === 'pgsql'
            ? "SUBSTR(to_char(recorded_at, 'YYYY-MM-DD HH24:MI'), 1, ".self::BUCKET_CHARS.')'
            : 'SUBSTR(recorded_at, 1, '.self::BUCKET_CHARS.')';

        $rows = TelemetryReading::query()
            ->toBase()
            ->selectRaw("{$bucket} as bucket, network_device_id")
            ->selectRaw('AVG(client_count) as clients, AVG(throughput_mbps) as throughput, AVG(latency_ms) as latency')
            ->whereIn('network_device_id', $accessPointIds)
            ->where('recorded_at', '>=', now()->subDay())
            ->groupByRaw("{$bucket}, network_device_id")
            ->get();

        return $rows->groupBy('bucket')
            ->sortKeys()
            ->map(function (Collection $perAccessPoint, string $bucket) use ($withLatency) {
                $at = Carbon::createFromFormat('Y-m-d H:i', $bucket.'0', 'UTC');
                $latencies = $perAccessPoint->pluck('latency')->filter(fn ($value) => $value !== null);

                return array_filter([
                    'at' => $at->toIso8601String(),
                    'label' => $at->campus()->format('H:i'),
                    'clients' => (int) round($perAccessPoint->sum(fn ($row) => (float) $row->clients)),
                    'throughput' => round($perAccessPoint->sum(fn ($row) => (float) $row->throughput), 1),
                    'latency' => $withLatency && $latencies->isNotEmpty() ? round($latencies->avg(), 1) : null,
                ], fn ($value) => $value !== null);
            })
            ->values()
            ->all();
    }

    /** Today's readings as a time; anything older with its date. On campus time. */
    private function campusTime(Carbon $moment): string
    {
        $local = $moment->campus();

        return $local->isSameDay(CampusClock::now())
            ? $local->format('H:i')
            : $local->format('j M, H:i');
    }
}
