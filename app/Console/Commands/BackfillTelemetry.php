<?php

namespace App\Console\Commands;

use App\Enums\ApStatus;
use App\Models\AccessPoint;
use App\Models\TelemetryReading;
use App\Services\Telemetry\SimulatedTelemetrySource;
use App\Services\Telemetry\TelemetryService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
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

        $existing = $this->alreadyRecorded($accessPoints, $cursor, $end);

        $rows = [];
        $written = 0;
        $skipped = 0;

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
                // A moment this access point already has a reading for is left
                // alone. See alreadyRecorded().
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
            $bar->advance();
        }

        if ($rows !== []) {
            TelemetryReading::insert($rows);
            $written += count($rows);
        }

        $bar->finish();
        $this->newLine(2);
        $this->info(number_format($written).' readings written across '.$accessPoints->count().' access points.');

        if ($skipped > 0) {
            $this->line('  '.number_format($skipped).' already recorded, left alone.');
        }

        $this->settle($accessPoints);

        // The charts read the aggregates, so history is useless until it has
        // been rolled up. Doing it here means one command gives a working
        // dashboard rather than two that must be run in the right order.
        $this->line('Aggregating…');
        Artisan::call('atlas:aggregate-telemetry', ['--hours' => $days * 24], $this->output);

        return self::SUCCESS;
    }

    /**
     * Every moment each access point already has a reading for, in this window.
     *
     * WHY. Nothing stopped a second run writing the same history again: no
     * unique index on (access_point_id, recorded_at), and no check here. Every
     * extra run silently doubled its window. The development database had
     * 18,830 surplus rows by the time anybody counted, and they were not
     * harmless copies — the simulator draws packet loss at random, so two
     * readings for the SAME instant could disagree about what happened, and
     * the hourly aggregates weighted whichever moments happened to be
     * duplicated twice.
     *
     * A unique index would be the stronger fix, and it cannot be added while
     * the duplicates exist: the migration would fail on the first one. So the
     * command makes itself safe to re-run instead. A second run fills gaps and
     * writes nothing it already has; `--fresh` still starts again from empty.
     *
     * Read through the query builder rather than the model, so a fortnight of
     * forty access points is a list of strings rather than 160,000 hydrated
     * Eloquent objects.
     *
     * @param  Collection<int, AccessPoint>  $accessPoints
     * @return array<int, array<string, true>> access point id => [moment => true]
     */
    private function alreadyRecorded(Collection $accessPoints, Carbon $from, Carbon $to): array
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
     * READ THE NEWEST READING FROM THE DATABASE, NOT THE LAST ONE WRITTEN HERE.
     * They are not the same thing, and the first version of this method
     * assumed they were. A backfill fills in history BEHIND whatever the live
     * collector has already recorded: its last reading lands on the last whole
     * hour, while the collector may have written one a few minutes ago. Settling
     * from the backfill's own last reading took an access point the collector
     * had just marked Offline — a real outage, with a ticket raised and the
     * engineers alerted — and flipped it back to Online from an invented reading
     * an hour older. It hid the outage. Found by running the command against a
     * live database; the test only ever had a backfill and nothing newer.
     *
     * So whatever wrote the newest row, that row wins, and a backfill can
     * never overrule a live measurement.
     *
     * WHY IT DOES NOT COST WHAT THE LOOP AVOIDS. The objection there is to a
     * query per READING — a quarter of a million of them. This is two small
     * indexed queries and one update per ACCESS POINT, once, after the writing
     * is finished: about forty access points, against 350,000 rows inserted.
     *
     * `last_seen_at` comes from the newest reading that actually ANSWERED, the
     * same rule store() applies. An access point that never answered keeps
     * whatever it had, because inventing a sighting is the one thing this must
     * not do.
     *
     * @param  Collection<int, AccessPoint>  $accessPoints
     */
    private function settle(Collection $accessPoints): void
    {
        $settled = 0;

        foreach ($accessPoints as $ap) {
            $newest = TelemetryReading::query()
                ->where('access_point_id', $ap->id)
                ->orderByDesc('recorded_at')
                ->first();

            if (! $newest) {
                continue;
            }

            // An unreachable reading has no latency; one that answered does.
            $lastAnswered = TelemetryReading::query()
                ->where('access_point_id', $ap->id)
                ->whereNotNull('latency_ms')
                ->orderByDesc('recorded_at')
                ->value('recorded_at');

            $ap->forceFill(array_filter([
                'status' => ApStatus::from($newest->status),
                'last_seen_at' => $lastAnswered,
            ], fn ($value) => $value !== null))->save();

            $settled++;
        }

        $this->line("  {$settled} access ".str('point')->plural($settled).' brought into line with their newest reading.');
    }
}
