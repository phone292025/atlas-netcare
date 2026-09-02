<?php

namespace App\Console\Commands;

use App\Models\TelemetryHourly;
use App\Models\TelemetryReading;
use Illuminate\Console\Command;

/**
 * Delete telemetry older than the retention window (Q10).
 *
 * Raw readings are the only high-volume table in the system — roughly 1.7
 * million rows a month — and hourly aggregates are small enough to keep for
 * far longer. Both periods live in config/atlas.php.
 *
 * Deleting in chunks rather than one statement: a single DELETE across a
 * million rows locks the table for long enough to notice, and this is
 * scheduled to run while the system is up.
 *
 * @see docs/02-open-questions.md Q10
 */
class PruneTelemetry extends Command
{
    protected $signature = 'atlas:prune-telemetry {--dry-run : Report what would go, delete nothing}';

    protected $description = 'Delete telemetry past its retention window';

    public function handle(): int
    {
        $rawBefore = now()->subDays((int) config('atlas.retention.raw_days'));
        $aggBefore = now()->subMonths((int) config('atlas.retention.aggregate_months'));

        $rawCount = TelemetryReading::where('recorded_at', '<', $rawBefore)->count();
        $aggCount = TelemetryHourly::where('hour_bucket', '<', $aggBefore)->count();

        if ($this->option('dry-run')) {
            $this->info("Would delete {$rawCount} raw readings before {$rawBefore->toDateString()}.");
            $this->info("Would delete {$aggCount} hourly rows before {$aggBefore->toDateString()}.");

            return self::SUCCESS;
        }

        $deletedRaw = 0;

        // Chunked, so a long-running delete never holds the table.
        do {
            $batch = TelemetryReading::where('recorded_at', '<', $rawBefore)->limit(2000)->delete();
            $deletedRaw += $batch;
        } while ($batch > 0);

        $deletedAgg = TelemetryHourly::where('hour_bucket', '<', $aggBefore)->delete();

        $this->info("Pruned {$deletedRaw} raw readings and {$deletedAgg} hourly rows.");

        return self::SUCCESS;
    }
}
