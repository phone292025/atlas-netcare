<?php

namespace App\Console\Commands;

use App\Models\TelemetryReading;
use App\Services\Telemetry\Simulator;
use App\Services\Telemetry\TelemetryBackfill;
use App\Services\Telemetry\TelemetryStorage;
use Illuminate\Console\Command;

/**
 * Invent plausible telemetry history, so the charts have something to draw.
 *
 * Without it the 24-hour chart stays empty until the collector has run for a
 * day.
 *
 * Coarser than live collection: a reading every five minutes, not every
 * minute. The hourly aggregates the charts read are indistinguishable, and it
 * is 350,000 rows for thirty days of forty access points instead of 1.7
 * million. Rows go in with chunked insert(), not create() in a loop.
 *
 * Only for the devices the simulator watches: inventing history for a device
 * a real agent measures would put fiction in front of an engineer. A re-run
 * stores nothing it already has, and each device's status follows its newest
 * reading only when that is newer than the one it has.
 *
 * @see docs/modules/M5-telemetry-and-dashboard.md
 * @see docs/05-data-strategy.md
 */
class BackfillTelemetry extends Command
{
    protected $signature = 'atlas:backfill-telemetry
                            {--days=14 : How many days of history to invent}
                            {--minutes=5 : Minutes between readings}
                            {--fresh : Delete existing simulated readings first}';

    protected $description = 'Generate simulated telemetry history so the charts have data';

    public function handle(TelemetryBackfill $backfill, TelemetryStorage $storage, Simulator $simulator): int
    {
        $devices = $simulator->watched()->get();

        if ($devices->isEmpty()) {
            $this->error('The simulator watches no devices, so there is nothing to invent history for.');
            $this->line('Real agents\' devices keep only what was measured.');

            return self::FAILURE;
        }

        if ($this->option('fresh')) {
            $this->warn('Deleting existing simulated readings…');
            TelemetryReading::query()->simulated()->delete();
        }

        $days = max(1, (int) $this->option('days'));
        $step = max(1, (int) $this->option('minutes'));

        $from = now()->subDays($days)->startOfHour();
        $end = now();

        $bar = $this->output->createProgressBar((int) ceil($from->diffInMinutes($end) / $step));
        $bar->start();

        // Moments a device already has a reading for come back as duplicates,
        // so a re-run fills gaps rather than doubling the window.
        $result = $backfill->write($devices, $from, $end, $step, fn () => $bar->advance());

        $bar->finish();
        $this->newLine(2);
        $this->info(number_format($result['written']).' readings written across '.$devices->count().' devices.');

        if ($result['skipped'] > 0) {
            $this->line('  '.number_format($result['skipped']).' already recorded, left alone.');
        }

        // The charts read the hourly summaries, and these hours are older
        // than the database's own refresh looks: bring them in now, so one
        // command gives a working dashboard.
        $this->line('Refreshing the hourly summaries…');
        $storage->refreshHourly(now()->subDays($days));

        return self::SUCCESS;
    }
}
