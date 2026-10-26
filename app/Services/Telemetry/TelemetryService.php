<?php

namespace App\Services\Telemetry;

use App\Enums\NetworkStatus;
use App\Models\AccessPoint;
use App\Models\TelemetryReading;
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
     * @return Collection<int, array{access_point: AccessPoint, from: NetworkStatus, to: NetworkStatus}>
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
     * Persist readings and update each access point's current state.
     *
     * @param  Collection<int, ReadingData>  $readings
     * @return Collection<int, array{access_point: AccessPoint, from: NetworkStatus, to: NetworkStatus}>
     */
    public function store(Collection $readings): Collection
    {
        $accessPoints = AccessPoint::query()
            ->whereIn('id', $readings->pluck('accessPointId')->unique())
            ->get()
            ->keyBy('id');

        $transitions = collect();

        DB::transaction(function () use ($readings, $accessPoints, &$transitions) {
            $rows = [];

            foreach ($readings as $reading) {
                $ap = $accessPoints->get($reading->accessPointId);

                if (! $ap) {
                    continue;
                }

                $status = $this->classify($ap, $reading);
                $rows[] = $reading->toRow($status->value);

                $from = $ap->status;

                if ($from !== $status) {
                    $transitions->push(['access_point' => $ap, 'from' => $from, 'to' => $status]);
                }

                /*
                 * The access point's own columns cache its latest reading, so
                 * lists need no subquery. Same transaction, so the two never
                 * disagree. `last_seen_at` only moves when the device actually
                 * answered: it is what the silence sweep reads.
                 *
                 * Saved quietly, so not audited: the reading row just written
                 * records this status, and at peak an access point near a
                 * threshold flaps every few minutes. A status an administrator
                 * sets, such as maintenance, is still audited.
                 */
                $ap->forceFill(array_filter([
                    'status' => $status,
                    'last_seen_at' => $reading->unreachable ? null : $reading->recordedAt,
                ], fn ($value) => $value !== null))->saveQuietly();
            }

            // Chunked insert(): a round is forty rows, a backfill hundreds of
            // thousands.
            foreach (array_chunk($rows, 500) as $chunk) {
                TelemetryReading::insert($chunk);
            }
        });

        return $transitions;
    }

    /**
     * What this reading means for this access point (Q1).
     *
     *   offline   — did not answer (silence is markStaleOffline()'s job)
     *   degraded  — latency, loss or saturation over threshold for
     *               `consecutive_breach` readings in a row, so one noisy
     *               sample cannot flip the status and raise an alert
     *   online    — neither
     *
     * Maintenance is kept regardless: it is an administrator's decision, not
     * a measurement.
     */
    public function classify(AccessPoint $ap, ReadingData $reading): NetworkStatus
    {
        if ($ap->status === NetworkStatus::Maintenance) {
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

        if (! $this->breachesThreshold($ap, $reading)) {
            return NetworkStatus::Online;
        }

        $required = (int) config('atlas.thresholds.consecutive_breach');

        // This reading is one of them, so only the previous n-1 are needed.
        $previous = TelemetryReading::query()
            ->where('access_point_id', $ap->id)
            ->orderByDesc('recorded_at')
            ->limit($required - 1)
            ->get();

        if ($previous->count() < $required - 1) {
            // Not enough history yet. A false Degraded on new kit trains
            // people to ignore the dashboard.
            return NetworkStatus::Online;
        }

        $allBreaching = $previous->every(
            fn (TelemetryReading $r) => $this->rowBreaches($ap, $r)
        );

        return $allBreaching ? NetworkStatus::Degraded : NetworkStatus::Online;
    }

    /** Whether a fresh reading is over any threshold. */
    private function breachesThreshold(AccessPoint $ap, ReadingData $reading): bool
    {
        return $this->overThresholds(
            $ap,
            $reading->latencyMs,
            $reading->packetLossPct,
            $reading->clientCount,
        );
    }

    /** The same test, against a stored row. */
    private function rowBreaches(AccessPoint $ap, TelemetryReading $reading): bool
    {
        return $this->overThresholds(
            $ap,
            $reading->latency_ms,
            (float) $reading->packet_loss_pct,
            $reading->client_count,
        );
    }

    /**
     * One threshold test for both paths, so a fresh reading and a stored one
     * are always judged identically.
     */
    private function overThresholds(AccessPoint $ap, ?int $latencyMs, float $lossPct, int $clients): bool
    {
        return $this->breachReason($ap, $latencyMs, $lossPct, $clients) !== null;
    }

    /**
     * Why a reading is over the limits, if it is: "slow" for latency or packet
     * loss, "busy" for an access point nearly at capacity. Both count towards
     * Degraded, but they are different faults, and a screen calling a full
     * access point slow sends somebody after the wrong one.
     */
    private function breachReason(AccessPoint $ap, ?int $latencyMs, float $lossPct, int $clients): ?string
    {
        $thresholds = config('atlas.thresholds');

        if (($latencyMs !== null && $latencyMs > $thresholds['latency_ms']) || $lossPct > $thresholds['packet_loss_pct']) {
            return 'slow';
        }

        $saturation = $ap->saturation($clients);

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
            ->orderBy('ap_code')
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
                    'code' => $ap->ap_code,
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
     * The most recent reading for each of these access points, in one query
     * rather than one per access point: this runs on every dashboard poll.
     *
     * @param  Collection<int, int>  $accessPointIds
     * @return Collection<int, TelemetryReading>
     */
    public function latestReadings(Collection $accessPointIds): Collection
    {
        if ($accessPointIds->isEmpty()) {
            return collect();
        }

        $latestIds = TelemetryReading::query()
            ->selectRaw('MAX(id) as id')
            ->whereIn('access_point_id', $accessPointIds)
            ->groupBy('access_point_id')
            ->pluck('id');

        return TelemetryReading::query()
            ->whereIn('id', $latestIds)
            ->get()
            ->keyBy('access_point_id');
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
     * @return Collection<int, array{access_point: AccessPoint, from: NetworkStatus, to: NetworkStatus}>
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

            $transitions->push(['access_point' => $ap, 'from' => $from, 'to' => NetworkStatus::Offline]);
        }

        return $transitions;
    }
}
