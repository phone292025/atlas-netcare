<?php

namespace App\Services\Telemetry;

use App\Enums\NetworkStatus;
use App\Models\TelemetryHourly;
use App\Models\TelemetryReading;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Raw readings rolled up into hourly buckets.
 *
 * IDEMPOTENT: the upsert against the unique (network_device_id, hour_bucket)
 * index means a second pass corrects a bucket rather than doubling every point
 * on the chart. Only hours that HAVE readings are touched, so a bucket written
 * directly by DemoDataSeeder survives unless something writes readings into
 * its hour.
 *
 * @see App\Console\Commands\AggregateTelemetry
 */
class TelemetryAggregator
{
    /** Rebuild every bucket from this hour onwards. Returns how many were written. */
    public function rebuildFrom(CarbonInterface $from): int
    {
        $rows = TelemetryReading::query()
            ->selectRaw($this->bucketExpression().' as bucket')
            ->selectRaw('network_device_id')
            ->selectRaw('source')
            ->selectRaw('AVG(CASE WHEN status != ? THEN latency_ms END) as avg_latency', [NetworkStatus::Offline->value])
            ->selectRaw('MAX(client_count) as peak_clients')
            ->selectRaw('AVG(packet_loss_pct) as avg_loss')
            ->selectRaw('AVG(channel_util_pct) as avg_util')
            ->selectRaw('COUNT(*) as reading_count')
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as offline_count', [NetworkStatus::Offline->value])
            ->where('recorded_at', '>=', Carbon::instance($from)->startOfHour())
            ->groupBy('bucket', 'network_device_id', 'source')
            ->get();

        $payload = $rows->map(fn ($row) => [
            'network_device_id' => $row->network_device_id,
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
                ['network_device_id', 'hour_bucket'],
                ['avg_latency_ms', 'peak_client_count', 'avg_packet_loss_pct',
                    'avg_channel_util_pct', 'uptime_pct', 'reading_count', 'source', 'updated_at'],
            );
        }

        return count($payload);
    }

    /**
     * Truncate `recorded_at` to the hour, in whichever database is running.
     *
     * The engines share no date-truncation function: `to_char` is
     * PostgreSQL's, `strftime` SQLite's and `DATE_FORMAT` MySQL's. Each
     * returns the same text, which the caller parses. Getting this wrong
     * passes review and fails the moment a test touches aggregation.
     */
    private function bucketExpression(): string
    {
        return match (DB::connection()->getDriverName()) {
            'pgsql' => "to_char(recorded_at, 'YYYY-MM-DD HH24:00:00')",
            'sqlite' => "strftime('%Y-%m-%d %H:00:00', recorded_at)",
            default => "DATE_FORMAT(recorded_at, '%Y-%m-%d %H:00:00')",
        };
    }
}
