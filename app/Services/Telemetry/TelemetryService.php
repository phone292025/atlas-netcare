<?php

namespace App\Services\Telemetry;

use App\Enums\ApStatus;
use App\Models\AccessPoint;
use App\Models\TelemetryReading;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Storing readings, deciding what they mean, and answering the dashboard.
 *
 * CLASSIFICATION LIVES HERE, not in a driver and not in a database trigger. A
 * driver reports numbers; whether those numbers amount to "degraded" is a
 * business rule with thresholds in config, and it needs the previous readings
 * — which a driver does not have.
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
     * Returns the access points whose status CHANGED, so the caller can act on
     * a transition — M4 raises a ticket when one goes offline, and only on the
     * transition. Reacting to every offline reading would raise one ticket a
     * minute for six hours.
     *
     * @return Collection<int, array{access_point: AccessPoint, from: ApStatus, to: ApStatus}>
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
     * @return Collection<int, array{access_point: AccessPoint, from: ApStatus, to: ApStatus}>
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
                 * The access point's own columns are a denormalised cache of
                 * its latest reading, so the dashboard and the inventory list
                 * need no subquery. Written in the same transaction as the
                 * reading, so the two can never disagree.
                 *
                 * last_seen_at is only touched when the AP actually answered —
                 * that is the column the offline rule reads, and stamping it
                 * on a failed poll would mean nothing is ever offline.
                 */
                $ap->forceFill(array_filter([
                    'status' => $status,
                    'last_seen_at' => $reading->unreachable ? null : $reading->recordedAt,
                ], fn ($value) => $value !== null))->save();
            }

            // Chunked insert() rather than create() in a loop: one round is 40
            // rows, but the backfill is hundreds of thousands.
            foreach (array_chunk($rows, 500) as $chunk) {
                TelemetryReading::insert($chunk);
            }
        });

        return $transitions;
    }

    /**
     * What this reading means for this access point (Q1).
     *
     *   offline   — did not answer. (Not answering AT ALL, for longer than
     *               offline_after_min, is markStaleOffline()'s job, not this
     *               method's — see below.)
     *   degraded  — latency, loss or saturation over threshold for
     *               `consecutive_breach` readings in a row
     *   online    — neither
     *
     * The consecutive requirement is what stops one noisy sample flipping an
     * access point's state, and with it the dashboard colour and an
     * auto-raised ticket.
     *
     * An access point under planned maintenance keeps that status regardless.
     * It is an administrative decision, not a measurement, and overwriting it
     * would start paging somebody about a device an engineer is holding.
     */
    public function classify(AccessPoint $ap, ReadingData $reading): ApStatus
    {
        if ($ap->status === ApStatus::Maintenance) {
            return ApStatus::Maintenance;
        }

        /*
         * A reading that arrived is proof of life, whatever `last_seen_at`
         * says. This used to ALSO consult last_seen_at, so that after any pause
         * in collection — a stopped scheduler, a laptop asleep, a demo restart
         * — the first run marked every access point offline on a reading that
         * proved it was up, raised one ticket per access point, and flipped
         * them all back online a minute later. Silence is judged only where
         * no reading arrives: markStaleOffline().
         */
        if ($reading->unreachable) {
            return ApStatus::Offline;
        }

        if (! $this->breachesThreshold($ap, $reading)) {
            return ApStatus::Online;
        }

        $required = (int) config('atlas.thresholds.consecutive_breach');

        // This reading is one of them, so we only need the previous n-1.
        $previous = TelemetryReading::query()
            ->where('access_point_id', $ap->id)
            ->orderByDesc('recorded_at')
            ->limit($required - 1)
            ->get();

        if ($previous->count() < $required - 1) {
            // Not enough history to be sure yet. Staying online is the safer
            // wrong answer: a false Degraded on a newly added access point
            // trains people to ignore the dashboard.
            return ApStatus::Online;
        }

        $allBreaching = $previous->every(
            fn (TelemetryReading $r) => $this->rowBreaches($ap, $r)
        );

        return $allBreaching ? ApStatus::Degraded : ApStatus::Online;
    }

    /** Whether a single fresh reading is over any threshold. */
    private function breachesThreshold(AccessPoint $ap, ReadingData $reading): bool
    {
        return $this->overThresholds(
            $ap,
            $reading->latencyMs,
            $reading->packetLossPct,
            $reading->clientCount,
        );
    }

    /** The same test, against a row already in the database. */
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
     * One threshold test, used by both paths.
     *
     * Written once rather than twice on purpose: a fresh reading and a stored
     * one must be judged identically, and two copies of this would drift into
     * "degraded now but not degraded a minute later" with no data change.
     */
    private function overThresholds(AccessPoint $ap, ?int $latencyMs, float $lossPct, int $clients): bool
    {
        $thresholds = config('atlas.thresholds');

        if ($latencyMs !== null && $latencyMs > $thresholds['latency_ms']) {
            return true;
        }

        if ($lossPct > $thresholds['packet_loss_pct']) {
            return true;
        }

        $saturation = $ap->saturation($clients);

        return $saturation !== null && $saturation > $thresholds['client_saturation'];
    }

    /**
     * The dashboard payload — counts by state, plus every access point's
     * current reading.
     *
     * Deliberately one method serving both the first page render and the
     * polling endpoint, so the two can never show different numbers.
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
         * When the newest reading was actually taken. `generated_at` is when
         * THIS payload was built, which is always "just now", and a screen
         * that reported only that said "updated 1s ago" over readings two days
         * old while calling every access point online. Stale means older than
         * the offline window: the collector has stopped, and the statuses on
         * this page are history, not measurement.
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
                'online' => $accessPoints->where('status', ApStatus::Online)->count(),
                'degraded' => $accessPoints->where('status', ApStatus::Degraded)->count(),
                'offline' => $accessPoints->where('status', ApStatus::Offline)->count(),
                'maintenance' => $accessPoints->where('status', ApStatus::Maintenance)->count(),
            ],
            'clients_now' => (int) $latest->sum('client_count'),
            'access_points' => $accessPoints->map(function (AccessPoint $ap) use ($latest) {
                $reading = $latest->get($ap->id);

                return [
                    'id' => $ap->id,
                    'code' => $ap->ap_code,
                    'location' => $ap->location?->code,
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
     * The most recent reading for each of these access points.
     *
     * One query for all of them rather than one per AP — this runs on every
     * dashboard poll, and forty extra queries every twenty seconds is exactly
     * the N+1 a marker looks for.
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
     * THE GAP THIS CLOSES. Every other status change in this service is driven
     * by a reading arriving. An access point that produces NO reading at all
     * would therefore keep its last known status indefinitely, and the
     * dashboard would go on reporting it online.
     *
     * This sweep is the ONLY place silence is judged. classify() used to check
     * `last_seen_at` as well, on readings that had just arrived, and after any
     * pause in collection it marked every access point offline and raised a
     * ticket for each one. See the decision log for 2026-09-04.
     *
     * With the simulator that cannot happen, because it invents a reading for
     * every collectable access point. It is exactly what a real SNMP driver
     * does, though: a device that does not answer enumeration is simply absent
     * from the batch, and ADR-0002's whole point is that the driver swaps.
     *
     * Runs on the same schedule as collection, so a device that goes quiet is
     * noticed within the same window as one that answers badly. Returns
     * transitions in the same shape as store(), so the caller raises the same
     * ticket and the same alarm — a machine that vanished and one that
     * answered with a failure are the same problem to whoever is on shift.
     *
     * Access points under maintenance are skipped. Being quiet is what
     * maintenance means.
     *
     * @return Collection<int, array{access_point: AccessPoint, from: ApStatus, to: ApStatus}>
     */
    public function markStaleOffline(): Collection
    {
        $cutoff = now()->subMinutes((int) config('atlas.thresholds.offline_after_min'));

        $stale = AccessPoint::query()
            ->collectable()
            ->where('status', '!=', ApStatus::Offline->value)
            ->where(fn ($q) => $q->whereNull('last_seen_at')->orWhere('last_seen_at', '<', $cutoff))
            ->get();

        $transitions = collect();

        foreach ($stale as $ap) {
            /*
             * An access point that has NEVER answered is left alone. It is
             * newly added kit that the collector has not reached yet, not a
             * device that has gone away, and alarming about it on the minute
             * it was created would train people to ignore the alarm.
             */
            if ($ap->last_seen_at === null) {
                continue;
            }

            $from = $ap->status;

            $ap->forceFill(['status' => ApStatus::Offline])->save();

            $transitions->push(['access_point' => $ap, 'from' => $from, 'to' => ApStatus::Offline]);
        }

        return $transitions;
    }
}
