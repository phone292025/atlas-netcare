<?php

namespace App\Services\Telemetry;

use App\Enums\NetworkStatus;
use App\Models\AccessPoint;
use App\Models\TelemetryReading;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use LogicException;

/**
 * Simulated history written behind the clock: by `atlas:backfill-telemetry`,
 * and by the first collection round after the scheduler has been off.
 *
 * Simulated driver only. Inventing history for real access points would put
 * fiction in front of an engineer, and a gap in real telemetry is a fact about
 * the network.
 *
 * @see App\Console\Commands\BackfillTelemetry
 * @see App\Services\Telemetry\TelemetryCollector
 */
class TelemetryBackfill
{
    /** Minutes between filled readings. Live collection is every minute; the charts cannot tell. */
    public const STEP_MINUTES = 5;

    public function __construct(
        private readonly TelemetrySource $source,
        private readonly TelemetryStorage $storage,
    ) {}

    /**
     * Fill the silence since collection stopped, so the charts do not come
     * back empty.
     *
     * Nothing happens when the newest reading is fresher than
     * `offline_after_min`, when nothing was ever collected (that is not a
     * gap), or with a real driver. The hourly summaries are refreshed over the
     * filled hours, so the hourly charts get them too.
     *
     * @return array{from: Carbon, written: int}|null
     */
    public function fillGap(): ?array
    {
        if (! $this->source instanceof SimulatedTelemetrySource) {
            return null;
        }

        $from = $this->gapStart();

        if (! $from || $from->diffInMinutes(now()) < (int) config('atlas.thresholds.offline_after_min')) {
            return null;
        }

        // What the driver polls, the same set live collection reads. An
        // access point whose feed is interrupted is not listened to, so its
        // silence is the point and must not be filled in.
        $accessPoints = $this->source->pollable()->get();

        if ($accessPoints->isEmpty()) {
            return null;
        }

        $written = $this->write($accessPoints, $from, now())['written'];

        if ($written === 0) {
            return null;
        }

        $this->storage->refreshHourly($from);

        return ['from' => $from, 'written' => $written];
    }

    /**
     * Readings every `$step` minutes from `$from` up to, not including, `$to`,
     * for each moment an access point does not already have one.
     *
     * The moments sit on a grid counted from the hour, so a re-run lands on
     * the same moments and finds its own rows.
     *
     * Status is set directly, not through classify(), which reads previous
     * readings: a query per row, a quarter of a million of them. Backfilled
     * history is scenery for the charts.
     *
     * @param  Collection<int, AccessPoint>  $accessPoints
     * @param  (Closure(): void)|null  $onStep  called once per moment, for a progress bar
     * @return array{written: int, skipped: int}
     */
    public function write(Collection $accessPoints, CarbonInterface $from, CarbonInterface $to, int $step = self::STEP_MINUTES, ?Closure $onStep = null): array
    {
        if (! $this->source instanceof SimulatedTelemetrySource) {
            throw new LogicException('Only the simulated driver can write history.');
        }

        $cursor = Carbon::instance($from)->startOfHour();

        while ($cursor->lt($from)) {
            $cursor->addMinutes($step);
        }

        $existing = $this->alreadyRecorded($accessPoints, $cursor, $to);
        $rows = [];
        $written = 0;
        $skipped = 0;

        while ($cursor->lt($to)) {
            foreach ($accessPoints as $ap) {
                $reading = $this->source->reading($ap, $cursor);

                if (isset($existing[$ap->id][$reading->recordedAt->toDateTimeString()])) {
                    $skipped++;

                    continue;
                }

                $status = $reading->unreachable ? NetworkStatus::Offline : NetworkStatus::Online;
                $rows[] = $reading->toRow($status->value);
            }

            if (count($rows) >= 2000) {
                TelemetryReading::insert($rows);
                $written += count($rows);
                $rows = [];
            }

            $cursor->addMinutes($step);

            if ($onStep) {
                $onStep();
            }
        }

        if ($rows !== []) {
            TelemetryReading::insert($rows);
            $written += count($rows);
        }

        return ['written' => $written, 'skipped' => $skipped];
    }

    /**
     * Where the gap begins: the newest reading, but never more than
     * `gap_fill_hours` back.
     *
     * Database-wide rather than per access point: one indexed lookup once a
     * minute.
     */
    private function gapStart(): ?Carbon
    {
        $newestReading = TelemetryReading::query()->max('recorded_at');

        // Never collected: nothing stopped. The chart says "No readings" itself.
        if ($newestReading === null) {
            return null;
        }

        return now()->subHours((int) config('atlas.gap_fill_hours'))->max(Carbon::parse($newestReading));
    }

    /**
     * Every moment each access point already has a reading for, in this window.
     *
     * Makes a re-run safe: it fills gaps and writes nothing it already has.
     * Without this, every run doubled its window, and the duplicates were not
     * harmless because the simulator draws loss at random. A unique index on
     * (network_device_id, recorded_at) would be stronger, but cannot be added
     * while old duplicates exist.
     *
     * Through the query builder, so a fortnight is a list of strings rather
     * than 160,000 hydrated models.
     *
     * @param  Collection<int, AccessPoint>  $accessPoints
     * @return array<int, array<string, true>> access point id => [moment => true]
     */
    private function alreadyRecorded(Collection $accessPoints, CarbonInterface $from, CarbonInterface $to): array
    {
        $recorded = [];

        TelemetryReading::query()
            ->toBase()
            ->select(['network_device_id', 'recorded_at'])
            ->whereIn('network_device_id', $accessPoints->pluck('id'))
            ->whereBetween('recorded_at', [$from, $to])
            ->orderBy('network_device_id')
            ->orderBy('recorded_at')
            ->chunk(5000, function ($rows) use (&$recorded): void {
                foreach ($rows as $row) {
                    $recorded[(int) $row->network_device_id][(string) $row->recorded_at] = true;
                }
            });

        return $recorded;
    }
}
