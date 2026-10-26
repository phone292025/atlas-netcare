<?php

namespace App\Console\Commands;

use App\Enums\NetworkStatus;
use App\Models\AccessPoint;
use App\Models\TelemetryReading;
use App\Services\Telemetry\TelemetryBackfill;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;

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
 * Refuses to run against a real driver, rather than invent history and stamp
 * it `live`.
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

    public function handle(TelemetryBackfill $backfill): int
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

        $from = now()->subDays($days)->startOfHour();
        $end = now();

        $bar = $this->output->createProgressBar((int) ceil($from->diffInMinutes($end) / $step));
        $bar->start();

        // Moments an access point already has a reading for are left alone,
        // so a re-run fills gaps rather than doubling the window.
        $result = $backfill->write($accessPoints, $from, $end, $step, fn () => $bar->advance());

        $bar->finish();
        $this->newLine(2);
        $this->info(number_format($result['written']).' readings written across '.$accessPoints->count().' access points.');

        if ($result['skipped'] > 0) {
            $this->line('  '.number_format($result['skipped']).' already recorded, left alone.');
        }

        $this->settle($accessPoints);

        // The charts read the aggregates, so roll the new history up here:
        // one command gives a working dashboard.
        $this->line('Aggregating…');
        Artisan::call('atlas:aggregate-telemetry', ['--hours' => $days * 24], $this->output);

        return self::SUCCESS;
    }

    /**
     * Bring each access point's cached `status` and `last_seen_at` into line
     * with its history, since this command bypasses store(), which normally
     * keeps them in step.
     *
     * From the NEWEST reading in the database, not the last one written here:
     * a backfill fills in BEHIND whatever the live collector recorded, and
     * settling from its own older reading once hid a real outage. A backfill
     * never overrules a live measurement.
     *
     * `last_seen_at` comes from the newest reading that answered, as in
     * store(). One that never answered keeps what it had: inventing a sighting
     * is the one thing this must not do. Two small queries per access point,
     * once, against hundreds of thousands of rows inserted.
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
                'status' => NetworkStatus::from($newest->status),
                'last_seen_at' => $lastAnswered,
            ], fn ($value) => $value !== null))->save();

            $settled++;
        }

        $this->line("  {$settled} access ".str('point')->plural($settled).' brought into line with their newest reading.');
    }
}
