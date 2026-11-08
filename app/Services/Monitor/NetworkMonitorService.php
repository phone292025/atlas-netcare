<?php

namespace App\Services\Monitor;

use App\Enums\DeviceKind;
use App\Enums\IncidentRule;
use App\Enums\IncidentStatus;
use App\Enums\NetworkStatus;
use App\Models\AccessPoint;
use App\Models\Agent;
use App\Models\DeviceStatusChange;
use App\Models\Incident;
use App\Models\NetworkDevice;
use App\Models\TelemetryReading;
use App\Models\User;
use App\Services\Telemetry\TelemetryService;
use App\Support\CampusClock;
use App\Support\Tone;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * What the Network monitor shows: what needs attention now, every device by
 * place, the agents, and campus activity over the last day.
 *
 * A read model across modules, which is why it is a module of its own and
 * not part of Telemetry: Telemetry publishes readings and status changes and
 * knows nothing of incidents, and the monitor has to show both side by side.
 * It writes nothing.
 *
 * One method serves the first render and every poll, for the same reason
 * TelemetryService::dashboardData() does: the two cannot disagree.
 *
 * STALE IS A DISPLAY STATE, NOT A STATUS. A device whose newest reading is
 * older than `offline_after_min` is shown as unknown rather than as its last
 * recorded status. Nothing is written: the stored status stays what the
 * classifier last concluded. This is how a stopped collector, or a simulated
 * feed interruption, reads as "we do not know" instead of a confident Online
 * from two weeks ago. Offline and maintenance are shown as they are: the
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

    /** The most incidents the page lists; the incidents screen has the rest. */
    private const INCIDENTS_SHOWN = 20;

    /** Worst first, for the list and the order of a place's devices. */
    private const STATE_ORDER = ['offline' => 0, 'degraded' => 1, 'stale' => 2, 'maintenance' => 3, 'online' => 4];

    /** Upstream first: what a place's access points hang from. */
    private const KIND_ORDER = ['router' => 0, 'switch' => 1, 'server' => 2, 'other' => 3, 'access_point' => 4];

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

    /**
     * Everything the page shows. The viewer decides only which incidents
     * they may take: everything else is the same for every role.
     *
     * @return array<string, mixed>
     */
    public function snapshot(?User $viewer = null): array
    {
        $devices = NetworkDevice::query()->with('location')->get();
        $latest = $this->telemetry->latestReadings($devices->pluck('id'));
        $since = $this->since($devices->pluck('id'));
        $incidents = $this->openIncidents();
        $incidentFor = $this->incidentByDevice($incidents);

        $rows = $devices
            ->map(fn (NetworkDevice $device) => $this->row($device, $latest->get($device->id), $since->get($device->id), $incidentFor->get($device->id)))
            ->sortBy([
                fn (array $a, array $b) => self::STATE_ORDER[$a['state']] <=> self::STATE_ORDER[$b['state']],
                fn (array $a, array $b) => strnatcmp($a['code'], $b['code']),
            ])
            ->values();

        $accessPointIds = $devices->filter(fn (NetworkDevice $d) => $d->kind === DeviceKind::AccessPoint)->pluck('id');
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
                // Of those, the ones whose agent isn't reporting.
                'silent' => $rows->where('silent', true)->count(),
            ],
            'kinds' => $this->kinds($rows),
            'clients_now' => (int) $rows->sum('clients'),
            'devices' => $rows->all(),
            'places' => $this->places($rows),
            'incidents' => $incidents->take(self::INCIDENTS_SHOWN)->map(fn (Incident $i) => $this->incident($i, $viewer))->values()->all(),
            'incidents_open' => $incidents->count(),
            'incidents_untaken' => $incidents->where('status', IncidentStatus::Open)->count(),
            'incidents_down' => $incidents->filter(fn (Incident $i) => in_array($i->rule, [IncidentRule::Offline, IncidentRule::Silent], true))->count(),
            'agents' => $this->agents(),
            'activity' => $this->activity($accessPointIds, $devices->pluck('id')),
        ];
    }

    /**
     * One device, for the panel beside the list: its figures, the path from
     * the core to it, what that path says about where the fault is, its
     * incident and its own day.
     *
     * @return array<string, mixed>
     */
    public function device(NetworkDevice $device, ?User $viewer = null): array
    {
        $device->loadMissing(['location', 'agent']);
        $path = $this->path($device);
        $ids = collect($path)->pluck('id')->push($device->id);
        $latest = $this->telemetry->latestReadings($ids);
        $since = $this->since($ids);
        $incident = $this->incidentByDevice($this->openIncidents($device))->get($device->id);
        $reading = $latest->get($device->id);
        $row = $this->row($device, $reading, $since->get($device->id), $incident);

        $upstream = collect($path)->map(function (NetworkDevice $hop) use ($latest) {
            $state = $this->state($hop, $latest->get($hop->id));

            return ['id' => $hop->id, 'code' => $hop->code, 'kind_label' => $hop->kind->label(), 'state' => $state, 'state_label' => $this->stateLabel($hop, $state)];
        })->all();

        return [
            ...$row,
            'model' => $device instanceof AccessPoint ? $device->model : null,
            'ip_address' => $device instanceof AccessPoint || $viewer?->isAdmin() ? $device->ip_address : null,
            'agent' => $viewer?->isAdmin() ? $device->agent?->name : null,
            'path' => $upstream,
            'verdict' => $this->verdict($device, $row, $upstream, $reading),
            'incident' => $incident ? $this->incident($incident->loadMissing('events'), $viewer, withEvents: true) : null,
            'activity' => $device->kind === DeviceKind::AccessPoint
                ? $this->activity(collect([$device->id]), collect([$device->id]))
                : $this->activity(collect(), collect([$device->id])),
        ];
    }

    /** @return array<string, mixed> */
    private function row(NetworkDevice $device, ?TelemetryReading $reading, ?CarbonInterface $changedAt, ?Incident $incident): array
    {
        $state = $this->state($device, $reading);
        $current = in_array($state, ['online', 'degraded'], true);
        $tone = $state === 'stale' ? Tone::NEUTRAL : $device->status->tone();
        $scenario = $device->simulation_scenario;
        $isAccessPoint = $device instanceof AccessPoint;

        // How long it has been in the state shown. Unknown counts from the
        // last thing Atlas heard; the others from the change into them.
        $from = $state === 'stale' ? $device->last_seen_at : ($changedAt ?? ($incident?->network_device_id === $device->id ? $incident->opened_at : null));

        return [
            'id' => $device->id,
            'code' => $device->code,
            'name' => $device->name,
            'kind' => $device->kind->value,
            'kind_label' => $device->kind->label(),
            'icon' => $device->kind->icon(),
            'parent_id' => $device->parent_id,
            'location' => $device->location?->code,
            'location_name' => $device->location?->name,
            'location_id' => $device->location_id,
            'state' => $state,
            'silent' => $device->status === NetworkStatus::Unknown,
            'state_label' => $this->stateLabel($device, $state),
            'since' => $from && $state !== 'online' ? $this->campusTime($from) : null,
            'for' => $from && $state !== 'online' ? $this->duration($from) : null,
            'badge' => Tone::badge($tone),
            'fill' => Tone::solid($tone),
            // Numbers are only shown when they describe now. A two-week-old
            // client count beside "Unknown" would contradict it.
            'clients' => $current && $isAccessPoint ? $reading?->client_count : null,
            'capacity' => $isAccessPoint ? $device->client_capacity : null,
            // Clamped for the bar: readings the simulator wrote before
            // 2026-09-19 can hold a negative utilisation, and a bar cannot.
            'utilization' => $current && $reading?->channel_util_pct !== null
                ? (int) max(0, min(100, round((float) $reading->channel_util_pct)))
                : null,
            'latency_ms' => $current ? $reading?->latency_ms : null,
            'loss_pct' => $current && $reading?->packet_loss_pct !== null ? round((float) $reading->packet_loss_pct, 1) : null,
            'last_seen' => $device->last_seen_at ? $this->campusTime($device->last_seen_at) : null,
            'incident_id' => $incident?->id,
            'scenario' => $scenario?->value,
            'scenario_label' => $scenario?->label(),
        ];
    }

    private function state(NetworkDevice $device, ?TelemetryReading $reading): string
    {
        if (in_array($device->status, [NetworkStatus::Maintenance, NetworkStatus::Offline], true)) {
            return $device->status->value;
        }

        // Its agent stopped reporting. The same as stale, said by the system
        // rather than worked out from the clock.
        if ($device->status === NetworkStatus::Unknown) {
            return 'stale';
        }

        $window = now()->subMinutes((int) config('atlas.thresholds.offline_after_min'));

        return $reading === null || $reading->recorded_at->lt($window) ? 'stale' : $device->status->value;
    }

    private function stateLabel(NetworkDevice $device, string $state): string
    {
        return $state === 'stale' ? 'Unknown' : $device->status->label();
    }

    /**
     * When each device last changed status, from the record Telemetry keeps.
     *
     * @param  Collection<int, int>  $ids
     * @return Collection<int, Carbon>
     */
    private function since(Collection $ids): Collection
    {
        return DeviceStatusChange::query()
            ->toBase()
            ->selectRaw('network_device_id, MAX(changed_at) as changed_at')
            ->whereIn('network_device_id', $ids)
            ->groupBy('network_device_id')
            ->pluck('changed_at', 'network_device_id')
            ->map(fn ($at) => Carbon::parse($at));
    }

    /**
     * Unresolved incidents, worst first: nobody has taken it, then what it
     * is (down before slow), then the oldest. With a device, only those it
     * is in, its own or one it joined behind a failed parent.
     *
     * @return Collection<int, Incident>
     */
    private function openIncidents(?NetworkDevice $covering = null): Collection
    {
        $rank = [IncidentRule::Offline->value => 0, IncidentRule::Silent->value => 1, IncidentRule::Flapping->value => 2, IncidentRule::Degraded->value => 3];

        return Incident::query()
            ->unresolved()
            ->whereNull('grouped_into_id')
            ->when($covering, fn ($query) => $query->where(fn ($q) => $q
                ->where('network_device_id', $covering->id)
                ->orWhereHas('devices', fn ($d) => $d->whereKey($covering->id)->whereNull('incident_devices.recovered_at'))))
            ->with(['device.location', 'agent', 'ticket', 'acknowledgedBy', 'alerts.user', 'devices'])
            ->get()
            ->sortBy([
                fn (Incident $a, Incident $b) => ($a->status === IncidentStatus::Open ? 0 : 1) <=> ($b->status === IncidentStatus::Open ? 0 : 1),
                fn (Incident $a, Incident $b) => $rank[$a->rule->value] <=> $rank[$b->rule->value],
                fn (Incident $a, Incident $b) => $a->opened_at <=> $b->opened_at,
            ])
            ->values();
    }

    /**
     * The open incident each device is in: its own, or the one it joined
     * behind a failed parent, while it hasn't recovered.
     *
     * @param  Collection<int, Incident>  $incidents
     * @return Collection<int, Incident>
     */
    private function incidentByDevice(Collection $incidents): Collection
    {
        $map = collect();

        foreach ($incidents as $incident) {
            if ($incident->network_device_id !== null) {
                $map->put($incident->network_device_id, $map->get($incident->network_device_id, $incident));
            }

            foreach ($incident->devices as $device) {
                if ($device->pivot->recovered_at === null && ! $map->has($device->id)) {
                    $map->put($device->id, $incident);
                }
            }
        }

        return $map;
    }

    /** @return array<string, mixed> */
    private function incident(Incident $incident, ?User $viewer, bool $withEvents = false): array
    {
        $told = $incident->alerts->pluck('user')->filter()->unique('id')->pluck('name');
        $behind = $incident->devices->filter(fn ($d) => $d->id !== $incident->network_device_id && $d->pivot->recovered_at === null)->count();

        return array_filter([
            'id' => $incident->id,
            'title' => $incident->title,
            'rule' => $incident->rule->value,
            'down' => in_array($incident->rule, [IncidentRule::Offline, IncidentRule::Silent], true),
            'status' => $incident->status->value,
            'status_label' => $incident->status->label(),
            'device_id' => $incident->device && ! $incident->device->trashed() ? $incident->network_device_id : null,
            'subject' => $incident->subjectLabel(),
            'place' => $incident->device?->location?->name,
            'behind' => $behind,
            'opened' => $this->campusTime($incident->opened_at),
            'for' => $this->duration($incident->opened_at),
            'ticket_id' => $incident->ticket_id,
            'ticket_number' => $incident->ticket?->ticket_number,
            'acknowledged_by' => $incident->acknowledgedBy?->name,
            'acknowledged_at' => $incident->acknowledged_at ? $this->campusTime($incident->acknowledged_at) : null,
            'told' => $told->take(3)->implode(', ').($told->count() > 3 ? ' and '.($told->count() - 3).' more' : ''),
            'can_acknowledge' => $incident->status === IncidentStatus::Open && $viewer?->can('acknowledge', $incident) === true,
            'events' => $withEvents
                ? $incident->events->sortByDesc('happened_at')->take(8)->sortBy('happened_at')
                    ->map(fn ($e) => ['at' => $this->campusTime($e->happened_at), 'message' => $e->message])->values()->all()
                : null,
        ], fn ($value) => $value !== null);
    }

    /**
     * From the core down to the device's parent, for the panel's path.
     *
     * @return list<NetworkDevice>
     */
    private function path(NetworkDevice $device): array
    {
        $path = [];

        // Bounded: the devices screen refuses a loop, and twelve hops is far
        // more than any campus has.
        for ($hop = $device->parent, $i = 0; $hop !== null && $i < 12; $hop = $hop->parent, $i++) {
            array_unshift($path, $hop);
        }

        return $path;
    }

    /**
     * What the device and its path say, in a sentence somebody can act on.
     *
     * @param  array<string, mixed>  $row
     * @param  list<array<string, mixed>>  $upstream
     */
    private function verdict(NetworkDevice $device, array $row, array $upstream, ?TelemetryReading $reading): string
    {
        $parent = collect($upstream)->last();
        $down = collect($upstream)->firstWhere('state', 'offline');
        $limits = config('atlas.thresholds');

        return match ($row['state']) {
            'offline' => match (true) {
                $down !== null => "{$down['code']} is down as well, so start there: everything behind it loses its link when it does.",
                $parent !== null => 'Its '.strtolower($parent['kind_label'])." {$parent['code']} answers, so the fault is at {$device->code} itself or its cable, not further up. Check its power and its port on {$parent['code']}.",
                default => 'No parent is recorded for it, so Atlas cannot tell whether the fault is here or further up. Set its parent on the devices screen.',
            },
            'degraded' => match (true) {
                $reading?->latency_ms !== null && $reading->latency_ms > $limits['latency_ms'] => "It answers, but slowly: {$reading->latency_ms} ms against the {$limits['latency_ms']} ms limit.",
                $reading?->packet_loss_pct !== null && (float) $reading->packet_loss_pct > $limits['packet_loss_pct'] => 'It answers, but loses '.round((float) $reading->packet_loss_pct, 1)."% of packets, over the {$limits['packet_loss_pct']}% limit.",
                $row['clients'] !== null && $row['capacity'] => "It answers, but it is nearly full: {$row['clients']} of {$row['capacity']} clients.",
                default => 'It answers, but its last readings were over a limit.',
            },
            'stale' => $row['silent']
                ? 'Its agent'.($device->agent ? " {$device->agent->name}" : '').' has stopped reporting, so Atlas does not know how it is. That is not the same as offline.'
                : 'No reading for more than '.$limits['offline_after_min'].' minutes, so its status is unknown. That is not the same as offline.',
            'maintenance' => 'Under maintenance: Atlas raises nothing for it until it is back.',
            default => $upstream === [] ? 'It answers.' : 'It and everything upstream of it answer.',
        };
    }

    /**
     * Each kind of device, and how many of it answer.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function kinds(Collection $rows): array
    {
        return $rows->groupBy('kind')
            ->sortBy(fn ($group, $kind) => self::KIND_ORDER[$kind] ?? 9)
            ->map(fn (Collection $group) => [
                'kind' => $group->first()['kind'],
                'label' => (string) str($group->first()['kind_label'])->plural($group->count()),
                'total' => $group->count(),
                'up' => $group->whereIn('state', ['online', 'degraded'])->count(),
            ])
            ->values()
            ->all();
    }

    /**
     * Devices grouped by where they are, places with a problem first. Within
     * a place, what the access points hang from comes first.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function places(Collection $rows): array
    {
        return $rows->groupBy(fn (array $row) => $row['location_id'] ?? 0)
            ->map(function (Collection $group) {
                $first = $group->first();
                $ordered = $group->sortBy([
                    fn (array $a, array $b) => (self::KIND_ORDER[$a['kind']] ?? 9) <=> (self::KIND_ORDER[$b['kind']] ?? 9),
                    fn (array $a, array $b) => strnatcmp($a['code'], $b['code']),
                ]);

                return [
                    'id' => $first['location_id'],
                    'code' => $first['location'],
                    'name' => $first['location_name'] ?? 'No place set',
                    'worst' => $group->min(fn (array $row) => self::STATE_ORDER[$row['state']]),
                    'clients' => (int) $group->sum('clients'),
                    'device_ids' => $ordered->pluck('id')->values()->all(),
                ];
            })
            ->sortBy([
                fn (array $a, array $b) => ($a['worst'] < self::STATE_ORDER['stale'] ? 0 : 1) <=> ($b['worst'] < self::STATE_ORDER['stale'] ? 0 : 1),
                fn (array $a, array $b) => strnatcasecmp($a['name'], $b['name']),
            ])
            ->values()
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function agents(): array
    {
        return Agent::query()
            ->whereHas('devices')
            ->orderBy('name')
            ->get()
            ->map(fn (Agent $agent) => [
                'name' => $agent->name,
                'simulator' => $agent->is_virtual,
                'silent' => $agent->silent_since !== null,
                'last_seen' => $agent->last_seen_at ? $this->duration($agent->last_seen_at).' ago' : 'never',
            ])
            ->all();
    }

    /**
     * Connected clients and throughput across these access points, and the
     * median-ish latency across these devices, in ten-minute points over the
     * last 24 hours. Each has its own chart, so each keeps its own unit.
     *
     * Grouped in SQL by the first fifteen characters of the timestamp as text
     * — `2026-09-19 14:3` — which is a ten-minute bucket. PostgreSQL stores a
     * real timestamp, so it formats it with `to_char` first. Averaged per
     * device within a bucket, then summed across devices, so a bucket that
     * holds eleven collection rounds does not count clients eleven times.
     *
     * @param  Collection<int, int>  $accessPointIds  whose clients and throughput count
     * @param  Collection<int, int>  $latencyIds  whose latency is averaged
     * @return list<array<string, mixed>>
     */
    private function activity(Collection $accessPointIds, Collection $latencyIds): array
    {
        $ids = $accessPointIds->merge($latencyIds)->unique();

        if ($ids->isEmpty()) {
            return [];
        }

        $bucket = DB::connection()->getDriverName() === 'pgsql'
            ? "SUBSTR(to_char(recorded_at, 'YYYY-MM-DD HH24:MI'), 1, ".self::BUCKET_CHARS.')'
            : 'SUBSTR(recorded_at, 1, '.self::BUCKET_CHARS.')';

        $rows = TelemetryReading::query()
            ->toBase()
            ->selectRaw("{$bucket} as bucket, network_device_id")
            ->selectRaw('AVG(client_count) as clients, AVG(throughput_mbps) as throughput, AVG(latency_ms) as latency')
            ->whereIn('network_device_id', $ids)
            ->where('recorded_at', '>=', now()->subDay())
            ->groupByRaw("{$bucket}, network_device_id")
            ->get();

        $aps = $accessPointIds->flip();
        $timed = $latencyIds->flip();

        return $rows->groupBy('bucket')
            ->sortKeys()
            ->map(function (Collection $perDevice, string $bucket) use ($aps, $timed) {
                $at = Carbon::createFromFormat('Y-m-d H:i', $bucket.'0', 'UTC');
                $ofAps = $perDevice->filter(fn ($row) => $aps->has($row->network_device_id));
                $latencies = $perDevice->filter(fn ($row) => $timed->has($row->network_device_id))->pluck('latency')->filter(fn ($v) => $v !== null);

                return [
                    'at' => $at->toIso8601String(),
                    'label' => $at->campus()->format('H:i'),
                    'clients' => $ofAps->isEmpty() ? null : (int) round($ofAps->sum(fn ($row) => (float) $row->clients)),
                    'throughput' => $ofAps->isEmpty() ? null : round($ofAps->sum(fn ($row) => (float) $row->throughput), 1),
                    'latency' => $latencies->isEmpty() ? null : round($latencies->avg(), 1),
                ];
            })
            ->values()
            ->all();
    }

    /** "23 min", "2 h 5 min", "3 days": how long, for a person to read. */
    private function duration(CarbonInterface $from): string
    {
        $minutes = (int) max(0, $from->diffInMinutes(now()));

        return match (true) {
            $minutes < 1 => (int) max(0, $from->diffInSeconds(now())).' s',
            $minutes < 60 => "{$minutes} min",
            $minutes < 60 * 24 => intdiv($minutes, 60).' h'.($minutes % 60 ? ' '.($minutes % 60).' min' : ''),
            default => intdiv($minutes, 60 * 24).' '.str('day')->plural(intdiv($minutes, 60 * 24)),
        };
    }

    /** Today's readings as a time; anything older with its date. On campus time. */
    private function campusTime(CarbonInterface $moment): string
    {
        $local = $moment->campus();

        return $local->isSameDay(CampusClock::now())
            ? $local->format('H:i')
            : $local->format('j M, H:i');
    }
}
