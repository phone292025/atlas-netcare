<?php

namespace App\Console\Commands;

use App\Services\Telemetry\TelemetryStorage as Storage;
use Illuminate\Console\Command;

/**
 * Set TimescaleDB's telemetry jobs from config, and bring the hourly
 * summaries up to date.
 *
 * The app container runs this every time it starts (docker/app/start.sh), so
 * a change to `config('atlas.retention')` takes effect on the next start. Safe
 * to repeat. Off TimescaleDB it says so and does nothing.
 *
 *   php artisan atlas:telemetry-storage
 *
 * @see App\Services\Telemetry\TelemetryStorage
 */
class TelemetryStorage extends Command
{
    protected $signature = 'atlas:telemetry-storage';

    protected $description = 'Set the TimescaleDB compression, retention and refresh jobs from config, and refresh the hourly summaries';

    public function handle(Storage $storage): int
    {
        if (! $storage->onTimescale()) {
            $this->line('TimescaleDB is not in use on this connection, so there are no jobs to set.');

            return self::SUCCESS;
        }

        foreach ($storage->applyPolicies() as $line) {
            $this->line($line);
        }

        $storage->refreshHourly();
        $this->info('Hourly summaries are up to date.');

        return self::SUCCESS;
    }
}
