<?php

namespace App\Services\Telemetry;

use App\Enums\NetworkStatus;
use App\Models\AccessPoint;
use App\Models\NetworkDevice;
use App\Models\TelemetryReading;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Storing readings, deciding what they mean, and answering the dashboard.
 *
 * Classification lives here, not in a driver: a driver reports numbers, and
 * whether they amount to "degraded" is a business rule with thresholds in
 * config that needs previous readings a driver does not have.
 *
 * @see docs/modules/M5-telemetry-and-dashboard.md
 * @see docs/02-open-questions.md Q1
 */
class TelemetryService
{
    public function __construct(private readonly TelemetrySource $source) {}

    /**
     * Take a round of readings and store them.
     *
     * Returns the access points whose status CHANGED, so the caller reacts to
     * a transition rather than to every reading: one ticket for an outage, not
     * one a minute for six hours.
     *
     * @return Collection<int, array{device: NetworkDevice, from: NetworkStatus, to: NetworkStatus}>
     */
    public function collectAndStore(): Collection
    {
        $readings = $this->source->collect();

        if ($readings->isEmpty()) {
            return collect();
        }

        return $this->store($readings);
    }

    /**
     * Persist readings and update each device's current state.
     *
     * @param  Collection<int, ReadingData>  $readings
     * @return Collection<int, array{device: NetworkDevice, from: NetworkStatus, to: NetworkStatus}>
     */
    public function store(Collection $readings): Collection
    {
        return $this->record($readings)->transitions;
    }

    /**
     * Persist readings, and say which were new and which devices changed.
     *
     * Readings are classified in the order they were measured, each with the
     * readings before it in view, including earlier ones in the same batch:
     * an agent that was offline sends its buffer as one batch.
     *
     * A reading that carries an agent and a sequence is stored once. A batch
     * sent twice, after a reply that never arrived, adds nothing the second
     * time (ADR-0017).
     *
     * Only a reading newer than the device's newest one moves its status, so
     * a late reading fills in history without turning a device that has
     * since recovered back to offline. `last_seen_at` moves only when the
     * device answered: it is what the silence sweep reads.
     *
     * @param  Collection<int, ReadingData>  $readings
     */
    public function record(Collection $readings): StoredReadings
    {
        $devices = NetworkDevice::query()
            ->whereIn('id', $readings->pluck('deviceId')->unique())
            ->get()
            ->keyBy('id');

        $ordered = $readings
            ->filter(fn (ReadingData $reading) => $devices->has($reading->deviceId))
            ->sortBy(fn (ReadingData $reading) => $reading->recordedAt->getTimestamp())
            ->values();

        $window = (int) config('atlas.thresholds.consecutive_breach') - 1;

        return DB::transaction(function () use ($ordered, $devices, $window): StoredReadings {
            $recent = [];
            $statuses = [];
            $rows = [];

            foreach ($ordered as $i => $reading) {
                $device = $devices->get($reading->deviceId);
                $recent[$device->id] ??= $this->recentBreaches($device, $window);

                $statuses[$i] = $this->classify($device, $reading, $recent[$device->id]);
                $rows[$i] = $reading->toRow($statuses[$i]->value);

                $recent[$device->id] = $recent[$device->id]
                    ->prepend($this->breachesThreshold($device, $reading))
                    ->take($window);
            }

            $stored = $this->insertOnce($rows);
            $transitions = collect();

            foreach ($devices as $device) {
                $mine = array_values(array_filter(
                    $stored,
                    fn (int $i) => $ordered[$i]->deviceId === $device->id,
                ));

                if ($mine === []) {
                    continue;
                }

                $newest = end($mine);
                $answered = array_filter($mine, fn (int $i) => ! $ordered[$i]->unreachable);
                $lastAnswer = $answered === [] ? null : $ordered[end($answered)]->recordedAt;

                if ($device->last_reading_at !== null && $ordered[$newest]->recordedAt->lte($device->last_reading_at)) {
                    // Late: history only.
                    continue;
                }

                $from = $device->status;
                $to = $statuses[$newest];

                if ($from !== $to) {
                    $transitions->push(['device' => $device, 'from' => $from, 'to' => $to]);
                }

                /*
                 * The device's own columns cache its latest reading, so lists
                 * need no subquery. Same transaction, so the two never
                 * disagree.
                 *
                 * Saved quietly, so not audited: the reading rows record this
                 * status, and at peak an access point near a threshold flaps
                 * every few minutes. A status an administrator sets, such as
                 * maintenance, is still audited.
                 */
                $device->forceFill(array_filter([
                    'status' => $to,
                    'last_reading_at' => $ordered[$newest]->recordedAt,
                    'last_seen_at' => $lastAnswer !== null && ($device->last_seen_at === null || $lastAnswer->gt($device->last_seen_at))
                        ? $lastAnswer
                        : null,
                ], fn ($value) => $value !== null))->saveQuietly();
            }

            return new StoredReadings(
                stored: collect($stored)->map(fn (int $i) => $ordered[$i])->values(),
                transitions: $transitions,
            );
        });
    }

    /**
     * Insert rows, each at most once, and return the indexes stored.
     *
     * Rows from an agent go through ON CONFLICT DO NOTHING on the unique key
     * of agent, sequence and time, and RETURNING says which were new. Rows
     * made inside Atlas carry no sequence and can't collide, so they are a
     * plain insert. Chunked: a round is forty rows, a backfill hundreds of
     * thousands.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return list<int>
     */
    private function insertOnce(array $rows): array
    {
        $plain = array_filter($rows, fn (array $row) => $row['sequence'] === null);
        $numbered = array_filter($rows, fn (array $row) => $row['sequence'] !== null);

        foreach (array_chunk($plain, 500) as $chunk) {
            TelemetryReading::insert($chunk);
        }

        $stored = array_keys($plain);

        if ($numbered === []) {
            sort($stored);

            return $stored;
        }

        $columns = array_keys(reset($numbered));
        $placeholder = '('.implode(', ', array_fill(0, count($columns), '?')).')';
        $index = [];

        foreach ($numbered as $i => $row) {
            $index[$row['agent_id'].':'.$row['sequence'].':'.$row['recorded_at']] = $i;
        }

        foreach (array_chunk($numbered, 200, preserve_keys: true) as $chunk) {
            $returned = DB::select(
                'INSERT INTO telemetry_readings ('.implode(', ', $columns).') VALUES '
                .implode(', ', array_fill(0, count($chunk), $placeholder))
                .' ON CONFLICT (agent_id, sequence, recorded_at) DO NOTHING RETURNING agent_id, sequence, recorded_at',
                array_merge(...array_map(fn (array $row) => array_values($row), array_values($chunk))),
            );

            foreach ($returned as $row) {
                $key = $row->agent_id.':'.$row->sequence.':'.Carbon::parse($row->recorded_at)->toDateTimeString();
                $stored[] = $index[$key];
            }
        }

        sort($stored);

        return $stored;
    }

    /**
     * What this reading means for this device (Q1).
     *
     *   offline   — did not answer (silence is markStaleOffline()'s job)
     *   degraded  — latency, loss or saturation over threshold for
     *               `consecutive_breach` readings in a row, so one noisy
     *               sample cannot flip the status and raise an alert
     *   online    — neither
     *
     * Maintenance is kept regardless: it is an administrator's decision, not
     * a measurement.
     *
     * `$earlier` is whether each of the readings before this one was over the
     * limits, newest first. Left out, they are read from the database.
     *
     * @param  Collection<int, bool>|null  $earlier
     */
    public function classify(NetworkDevice $device, ReadingData $reading, ?Collection $earlier = null): NetworkStatus
    {
        if ($device->status === NetworkStatus::Maintenance) {
            return NetworkStatus::Maintenance;
        }

        /*
         * A reading that arrived is proof of life, whatever `last_seen_at`
         * says. Consulting it here marked every access point offline on the
         * first round after any pause in collection.
         */
        if ($reading->unreachable) {
            return NetworkStatus::Offline;
        }

        if (! $this->breachesThreshold($device, $reading)) {
            return NetworkStatus::Online;
        }

        // This reading is one of them, so only the previous n-1 are needed.
        $needed = (int) config('atlas.thresholds.consecutive_breach') - 1;
        $earlier ??= $this->recentBreaches($device, $needed);

        if ($earlier->count() < $needed) {
            // Not enough history yet. A false Degraded on new kit trains
            // people to ignore the dashboard.
            return NetworkStatus::Online;
        }

        return $earlier->take($needed)->every(fn (bool $breached) => $breached)
            ? NetworkStatus::Degraded
            : NetworkStatus::Online;
    }

    /**
     * Whether each of a device's newest stored readings was over the limits,
     * newest first.
     *
     * @return Collection<int, bool>
     */
    private function recentBreaches(NetworkDevice $device, int $count): Collection
    {
        if ($count < 1) {
            return collect();
        }

        return TelemetryReading::query()
            ->where('network_device_id', $device->id)
            ->orderByDesc('recorded_at')
            ->limit($count)
            ->get()
            ->map(fn (TelemetryReading $r) => $this->rowBreaches($device, $r))
            ->values();
    }

    /** Whether a fresh reading is over any threshold. */
    private function breachesThreshold(NetworkDevice $device, ReadingData $reading): bool
    {
        return $this->overThresholds(
            $device,
            $reading->latencyMs,
            $reading->packetLossPct,
            $reading->clientCount,
        );
    }

    /** The same test, against a stored row. */
    private function rowBreaches(NetworkDevice $device, TelemetryReading $reading): bool
    {
        return $this->overThresholds(
            $device,
            $reading->latency_ms,
            (float) $reading->packet_loss_pct,
            $reading->client_count,
        );
    }

    /**
     * One threshold test for both paths, so a fresh reading and a stored one
     * are always judged identically.
     */
    private function overThresholds(NetworkDevice $device, ?int $latencyMs, float $lossPct, int $clients): bool
    {
        return $this->breachReason($device, $latencyMs, $lossPct, $clients) !== null;
    }

    /**
     * Why a reading is over the limits, if it is: "slow" for latency or packet
     * loss, "busy" for an access point nearly at capacity. Both count towards
     * Degraded, but they are different faults, and a screen calling a full
     * access point slow sends somebody after the wrong one.
     */
    private function breachReason(NetworkDevice $device, ?int $latencyMs, float $lossPct, int $clients): ?string
    {
        $thresholds = config('atlas.thresholds');

        if (($latencyMs !== null && $latencyMs > $thresholds['latency_ms']) || $lossPct > $thresholds['packet_loss_pct']) {
            return 'slow';
        }

        // Only an access point has a capacity to be busy against.
        $saturation = $device instanceof AccessPoint ? $device->saturation($clients) : null;

        return $saturation !== null && $saturation > $thresholds['client_saturation'] ? 'busy' : null;
    }

    /**
     * The dashboard payload: counts by state, plus every access point's
     * current reading. One method for the first render and the polling
     * endpoint, so the two can never show different numbers.
     *
     * @return array<string, mixed>
     */
    public function dashboardData(): array
    {
        $accessPoints = AccessPoint::query()
            ->with('location')
            ->orderBy('code')
            ->get();

        $latest = $this->latestReadings($accessPoints->pluck('id'));

        /*
         * When the newest reading was taken, as well as when this payload was
         * built. Only reporting the latter said "updated 1s ago" over readings
         * two days old. Stale means older than the offline window: the
         * collector has stopped.
         */
        $newest = $latest->max('recorded_at');
        $stale = $newest !== null
            && $newest->lt(now()->subMinutes((int) config('atlas.thresholds.offline_after_min')));

        return [
            'generated_at' => now()->toIso8601String(),
            'readings_as_of' => $newest?->toIso8601String(),
            'readings_age' => $newest?->diffForHumans(),
            'stale' => $stale,
            'counts' => [
                'total' => $accessPoints->count(),
                'online' => $accessPoints->where('status', NetworkStatus::Online)->count(),
                'degraded' => $accessPoints->where('status', NetworkStatus::Degraded)->count(),
                'offline' => $accessPoints->where('status', NetworkStatus::Offline)->count(),
                'maintenance' => $accessPoints->where('status', NetworkStatus::Maintenance)->count(),
            ],
            'clients_now' => (int) $latest->sum('client_count'),
            'access_points' => $accessPoints->map(function (AccessPoint $ap) use ($latest) {
                $reading = $latest->get($ap->id);
                $breach = $reading === null ? null : $this->breachReason(
                    $ap, $reading->latency_ms, (float) $reading->packet_loss_pct, $reading->client_count,
                );

                return [
                    'id' => $ap->id,
                    'code' => $ap->code,
                    'location' => $ap->location?->code,
                    'location_name' => $ap->location?->name,
                    // The latest reading over the limits, and why ("slow" or
                    // "busy"), whatever the status says yet: it turns Degraded
                    // only after several in a row.
                    'over_threshold' => $breach !== null,
                    'breach' => $breach,
                    'status' => $ap->status->value,
                    'status_label' => $ap->status->label(),
                    'tone' => $ap->status->tone(),
                    'clients' => $reading?->client_count ?? 0,
                    'capacity' => $ap->client_capacity,
                    'latency_ms' => $reading?->latency_ms,
                    'packet_loss_pct' => $reading ? (float) $reading->packet_loss_pct : null,
                    'last_seen' => $ap->last_seen_at?->diffForHumans(),
                ];
            })->values()->all(),
        ];
    }

    /**
     * The most recent reading for each of these devices, in one query rather
     * than one per device: this runs on every dashboard poll.
     *
     * @param  Collection<int, int>  $deviceIds
     * @return Collection<int, TelemetryReading>
     */
    public function latestReadings(Collection $deviceIds): Collection
    {
        if ($deviceIds->isEmpty()) {
            return collect();
        }

        $ids = $deviceIds->map(fn ($id) => (int) $id)->unique()->values();

        if (DB::getDriverName() === 'pgsql') {
            /*
             * One newest row per device, through LATERAL and LIMIT 1. On the
             * hypertable that reads the newest chunk first and stops at the
             * first row, however many weeks are stored. A GROUP BY for each
             * device's newest time would read every chunk.
             */
            $rows = DB::select(
                'SELECT r.* FROM unnest(?::bigint[]) AS d(id) '
                .'CROSS JOIN LATERAL (SELECT * FROM telemetry_readings WHERE network_device_id = d.id ORDER BY recorded_at DESC LIMIT 1) AS r',
                ['{'.$ids->implode(',').'}'],
            );

            return TelemetryReading::hydrate($rows)->keyBy('network_device_id');
        }

        // SQLite, for the quick local suite: each device's newest time, then
        // the row at that time.
        return TelemetryReading::query()
            ->whereIn('network_device_id', $ids)
            ->whereRaw('recorded_at = (SELECT MAX(newest.recorded_at) FROM telemetry_readings AS newest WHERE newest.network_device_id = telemetry_readings.network_device_id)')
            ->get()
            ->keyBy('network_device_id');
    }

    /**
     * Mark access points offline that have simply stopped being heard from.
     *
     * Every other status change is driven by a reading arriving. A real SNMP
     * driver omits a device it cannot enumerate, so without this sweep that
     * device would keep its last status forever. This is the ONLY place
     * silence is judged. Returns transitions in the same shape as store(), so
     * the caller raises the same ticket and alert.
     *
     * @return Collection<int, array{device: NetworkDevice, from: NetworkStatus, to: NetworkStatus}>
     */
    public function markStaleOffline(): Collection
    {
        $cutoff = now()->subMinutes((int) config('atlas.thresholds.offline_after_min'));

        /*
         * Only what the driver polls. Silence is evidence of failure only if
         * somebody was listening: marking an unpolled device offline raises a
         * ticket about hardware nothing tried to reach. Maintenance is outside
         * pollable() too, because being quiet is what maintenance means.
         */
        $stale = $this->source->pollable()
            ->where('status', '!=', NetworkStatus::Offline->value)
            ->where(fn ($q) => $q->whereNull('last_seen_at')->orWhere('last_seen_at', '<', $cutoff))
            ->get();

        $transitions = collect();

        foreach ($stale as $ap) {
            // Never answered: new kit the collector has not reached yet, not a
            // device that has gone away.
            if ($ap->last_seen_at === null) {
                continue;
            }

            $from = $ap->status;

            // Quietly, as in store(). The ticket and the alert are the record.
            $ap->forceFill(['status' => NetworkStatus::Offline])->saveQuietly();

            $transitions->push(['device' => $ap, 'from' => $from, 'to' => NetworkStatus::Offline]);
        }

        return $transitions;
    }
}
