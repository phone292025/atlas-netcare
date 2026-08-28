<?php

namespace App\Console\Commands;

use App\Models\AccessPoint;
use App\Models\TelemetryReading;
use App\Services\Telemetry\SimulatedTelemetrySource;
use App\Services\Telemetry\TelemetryService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

/**
 * Invent plausible history, so the charts have something to draw.
 *
 * A dashboard whose 24-hour chart is empty until you have left the collector
 * running for 24 hours is not demonstrable, and the demo is worth 40%.
 *
 * COARSER THAN LIVE COLLECTION — one reading every five minutes rather than
 * every minute. Thirty days at one-minute resolution for forty access points
 * is 1.7 million rows and takes minutes to write; at five it is 350,000 and
 * the hourly aggregates, which is all the charts read, are indistinguishable.
 *
 * Rows are written with chunked insert() rather than create() in a loop —
 * a quarter of a million model instantiations is the difference between
 * seconds and a coffee break.
 *
 * Simulated only, and it says so: this refuses to run against the SNMP driver
 * rather than inventing history and stamping it `live`.
 *
 * @see docs/modules/M5-telemetry-and-dashboard.md
 * @see docs/07-data-strategy.md
 */
class BackfillTelemetry extends Command
{
    protected $signature = 'atlas:backfill-telemetry
                            {--days=14 : How many days of history to invent}
                            {--minutes=5 : Minutes between readings}
                            {--fresh : Delete existing simulated readings first}';

    protected $description = 'Generate simulated telemetry history so the charts have data';

    public function handle(TelemetryService $telemetry): int
    {
        if (config('atlas.telemetry_driver') !== 'simulated') {
            $this->error('Backfill only makes sense with ATLAS_TELEMETRY_DRIVER=simulated.');
            $this->line('Inventing history and stamping it `live` would put fiction in front of an engineer.');

            return self::FAILURE;
        }

        $accessPoints = AccessPoint::query()->collectable()->get();

        if ($accessPoints->isEmpty()) {
            $this->error('No access points to generate readings for.');

            return self::FAILURE;
        }

        if ($this->option('fresh')) {
            $this->warn('Deleting existing simulated readings…');
            TelemetryReading::query()->simulated()->delete();
        }

        $days = max(1, (int) $this->option('days'));
        $step = max(1, (int) $this->option('minutes'));

        $source = new SimulatedTelemetrySource;
        $cursor = now()->subDays($days)->startOfHour();
        $end = now();

        $rows = [];
        $written = 0;

        $bar = $this->output->createProgressBar((int) ceil($cursor->diffInMinutes($end) / $step));
        $bar->start();

        while ($cursor->lt($end)) {
            foreach ($accessPoints as $ap) {
                $reading = $source->reading($ap, $cursor);

                /*
                 * Status is written directly rather than through
                 * TelemetryService::classify(), on purpose. Classification
                 * reads the PREVIOUS readings from the database, and doing
                 * that per row here is a query per reading — a quarter of a
                 * million of them. Backfilled history is scenery for the
                 * charts; live classification is what the dashboard shows.
                 */
                $rows[] = $reading->toRow($reading->unreachable ? 'offline' : 'online');
            }

            if (count($rows) >= 2000) {
                TelemetryReading::insert($rows);
                $written += count($rows);
                $rows = [];
            }

            $cursor->addMinutes($step);
            $bar->advance();
        }

        if ($rows !== []) {
            TelemetryReading::insert($rows);
            $written += count($rows);
        }

        $bar->finish();
        $this->newLine(2);
        $this->info(number_format($written).' readings written across '.$accessPoints->count().' access points.');

        // The charts read the aggregates, so history is useless until it has
        // been rolled up. Doing it here means one command gives a working
        // dashboard rather than two that must be run in the right order.
        $this->line('Aggregating…');
        Artisan::call('atlas:aggregate-telemetry', ['--hours' => $days * 24], $this->output);

        return self::SUCCESS;
    }
}
