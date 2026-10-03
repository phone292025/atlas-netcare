<?php

namespace App\Services\Telemetry;

use App\Enums\ApStatus;
use App\Models\AccessPoint;
use App\Models\TelemetryReading;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use LogicException;

/**
 * Simulated history written behind the clock, for `atlas:backfill-telemetry`.
 *
 * Simulated driver only. Inventing history for real access points would put
 * fiction in front of an engineer, and a gap in real telemetry is a fact about
 * the network.
 *
 * @see App\Console\Commands\BackfillTelemetry
 */
class TelemetryBackfill
{
    /** Minutes between filled readings. Live collection is every minute; the charts cannot tell. */
    public const STEP_MINUTES = 5;

    public function __construct(private readonly TelemetrySource $source) {}

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

                $status = $reading->unreachable ? ApStatus::Offline : ApStatus::Online;
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
     * Every moment each access point already has a reading for, in this window.
     *
     * Makes a re-run safe: it fills gaps and writes nothing it already has.
     * Without this, every run doubled its window, and the duplicates were not
     * harmless because the simulator draws loss at random. A unique index on
     * (access_point_id, recorded_at) would be stronger, but cannot be added
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
            ->select(['access_point_id', 'recorded_at'])
            ->whereIn('access_point_id', $accessPoints->pluck('id'))
            ->whereBetween('recorded_at', [$from, $to])
            ->orderBy('id')
            ->chunk(5000, function ($rows) use (&$recorded): void {
                foreach ($rows as $row) {
                    $recorded[(int) $row->access_point_id][(string) $row->recorded_at] = true;
                }
            });

        return $recorded;
    }
}
