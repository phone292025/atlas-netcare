<?php

namespace App\Console\Commands;

use App\Enums\ApStatus;
use App\Models\AccessPoint;
use App\Models\TelemetryReading;
use App\Services\Telemetry\SimulatedTelemetrySource;
use App\Services\Telemetry\TelemetryService;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
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
 * @see docs/05-data-strategy.md
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

        // The last thing each access point reported, so its own columns can be
        // brought into line once at the end. See settle() below.
        $finalStatus = [];
        $lastSeen = [];

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
                $status = $reading->unreachable ? ApStatus::Offline : ApStatus::Online;
                $rows[] = $reading->toRow($status->value);

                $finalStatus[$ap->id] = $status;

                if (! $reading->unreachable) {
                    // Copied: $cursor is mutated at the end of every pass.
                    $lastSeen[$ap->id] = $reading->recordedAt->copy();
                }
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

        $this->settle($accessPoints, $finalStatus, $lastSeen);

        // The charts read the aggregates, so history is useless until it has
        // been rolled up. Doing it here means one command gives a working
        // dashboard rather than two that must be run in the right order.
        $this->line('Aggregating…');
        Artisan::call('atlas:aggregate-telemetry', ['--hours' => $days * 24], $this->output);

        return self::SUCCESS;
    }

    /**
     * Bring each access point's own columns into line with the history just
     * written.
     *
     * WHY THIS IS NEEDED. `access_points.status` and `last_seen_at` are a
     * denormalised cache of the newest reading, and TelemetryService::store()
     * is what normally keeps them in step. This command bypasses store() for
     * the reason given in the loop above, so before this method existed the
     * rows and the cache simply disagreed: the AP health table read `status`
     * from the access point and latency and loss from the latest reading, and
     * an access point whose invented fortnight happened to end on an outage
     * rendered a row saying Online, no latency, 100% packet loss. One
     * self-contradictory row out of twenty-nine, and nothing in the system
     * could tell you which half was right.
     *
     * WHY IT DOES NOT COST WHAT THE LOOP AVOIDS. The objection there is to a
     * query per READING — a quarter of a million of them. This is one update
     * per ACCESS POINT, once, after the writing is finished: forty, against
     * 350,000 rows already inserted.
     *
     * `last_seen_at` moves only for an access point that actually answered,
     * the same rule store() applies. One that was unreachable for the whole
     * window keeps whatever it had, because inventing a sighting is the one
     * thing this must not do.
     *
     * @param  Collection<int, AccessPoint>  $accessPoints
     * @param  array<int, ApStatus>  $finalStatus
     * @param  array<int, CarbonInterface>  $lastSeen
     */
    private function settle(Collection $accessPoints, array $finalStatus, array $lastSeen): void
    {
        $settled = 0;

        foreach ($accessPoints as $ap) {
            if (! isset($finalStatus[$ap->id])) {
                continue;
            }

            $ap->forceFill(array_filter([
                'status' => $finalStatus[$ap->id],
                'last_seen_at' => $lastSeen[$ap->id] ?? null,
            ], fn ($value) => $value !== null))->save();

            $settled++;
        }

        $this->line("  {$settled} access ".str('point')->plural($settled).' brought into line with their newest reading.');
    }
}
