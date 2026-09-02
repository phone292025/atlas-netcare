<?php

namespace App\Console\Commands;

use App\Enums\ApStatus;
use App\Models\TelemetryHourly;
use App\Models\TelemetryReading;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

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

    public function handle(): int
    {
        $hours = max(1, (int) $this->option('hours'));

        /*
         * Rebuild a few hours rather than only the last one. A collection run
         * that was late, or a backfill that landed after the previous
         * aggregation, would otherwise leave a permanently wrong bucket that
         * nothing ever revisits.
         */
        $from = now()->subHours($hours)->startOfHour();

        $rows = TelemetryReading::query()
            ->selectRaw($this->bucketExpression().' as bucket')
            ->selectRaw('access_point_id')
            ->selectRaw('source')
            ->selectRaw('AVG(CASE WHEN status != ? THEN latency_ms END) as avg_latency', [ApStatus::Offline->value])
            ->selectRaw('MAX(client_count) as peak_clients')
            ->selectRaw('AVG(packet_loss_pct) as avg_loss')
            ->selectRaw('AVG(channel_util_pct) as avg_util')
            ->selectRaw('COUNT(*) as reading_count')
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as offline_count', [ApStatus::Offline->value])
            ->where('recorded_at', '>=', $from)
            ->groupBy('bucket', 'access_point_id', 'source')
            ->get();

        if ($rows->isEmpty()) {
            $this->info('No readings to aggregate.');

            return self::SUCCESS;
        }

        $payload = $rows->map(fn ($row) => [
            'access_point_id' => $row->access_point_id,
            'hour_bucket' => Carbon::parse($row->bucket)->startOfHour()->toDateTimeString(),

            /*
             * Latency averages EXCLUDE offline readings. An unreachable access
             * point has no latency — counting it as zero would make an outage
             * read as the best hour that AP ever had, which is precisely
             * backwards.
             */
            'avg_latency_ms' => $row->avg_latency !== null ? round((float) $row->avg_latency, 2) : null,

            // Peak, not average. A room that hit capacity for ten minutes is
            // the fact worth keeping; the mean hides it.
            'peak_client_count' => (int) $row->peak_clients,

            'avg_packet_loss_pct' => round((float) $row->avg_loss, 2),
            'avg_channel_util_pct' => round((float) $row->avg_util, 2),
            'uptime_pct' => $row->reading_count > 0
                ? round((1 - $row->offline_count / $row->reading_count) * 100, 2)
                : 100,
            'reading_count' => (int) $row->reading_count,
            'source' => $row->source,
            'created_at' => now(),
            'updated_at' => now(),
        ])->all();

        foreach (array_chunk($payload, 500) as $chunk) {
            TelemetryHourly::upsert(
                $chunk,
                ['access_point_id', 'hour_bucket'],
                ['avg_latency_ms', 'peak_client_count', 'avg_packet_loss_pct',
                    'avg_channel_util_pct', 'uptime_pct', 'reading_count', 'source', 'updated_at'],
            );
        }

        $this->info(count($payload).' hourly '.str('bucket')->plural(count($payload)).' written.');

        return self::SUCCESS;
    }

    /**
     * Truncate `recorded_at` to the hour, in whichever database is running.
     *
     * MariaDB ships and SQLite runs the tests, and they have no shared
     * date-truncation function — `DATE_FORMAT` is MySQL's, `strftime` is
     * SQLite's. Getting this wrong passes review and fails the moment a test
     * touches aggregation.
     */
    private function bucketExpression(): string
    {
        return DB::connection()->getDriverName() === 'sqlite'
            ? "strftime('%Y-%m-%d %H:00:00', recorded_at)"
            : "DATE_FORMAT(recorded_at, '%Y-%m-%d %H:00:00')";
    }
}
