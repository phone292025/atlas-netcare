<?php

namespace App\Console\Commands;

use App\Services\Telemetry\TelemetryAggregator;
use Illuminate\Console\Command;

/**
 * Roll raw readings up into hourly buckets.
 *
 * Charts read `telemetry_hourly`, never the raw table: 24 hours of raw data
 * for one access point is 1,440 rows, and the same period here is 24. NFR-02
 * wants the detail chart under two seconds.
 *
 * IDEMPOTENT. Runs hourly on the schedule, but it can be run by hand, run
 * twice, or overlap itself — the upsert against the unique
 * (access_point_id, hour_bucket) index means a second pass corrects the row
 * rather than doubling every point on the chart.
 *
 * @see docs/modules/M5-telemetry-and-dashboard.md
 * @see docs/02-open-questions.md Q9
 */
class AggregateTelemetry extends Command
{
    protected $signature = 'atlas:aggregate-telemetry
                            {--hours=3 : How many hours back to rebuild}';

    protected $description = 'Roll raw telemetry readings into hourly aggregates';

    public function handle(TelemetryAggregator $aggregator): int
    {
        $hours = max(1, (int) $this->option('hours'));

        /*
         * Rebuild a few hours rather than only the last one. A collection run
         * that was late, or a backfill that landed after the previous
         * aggregation, would otherwise leave a permanently wrong bucket that
         * nothing ever revisits.
         */
        $written = $aggregator->rebuildFrom(now()->subHours($hours)->startOfHour());

        if ($written === 0) {
            $this->info('No readings to aggregate.');

            return self::SUCCESS;
        }

        $this->info($written.' hourly '.str('bucket')->plural($written).' written.');

        return self::SUCCESS;
    }
}
