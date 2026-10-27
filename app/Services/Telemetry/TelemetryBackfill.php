<?php

namespace App\Services\Telemetry;

use App\Models\NetworkDevice;
use App\Models\TelemetryReading;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Simulated history written behind the clock: by `atlas:backfill-telemetry`,
 * and by the first collection round after the scheduler has been off.
 *
 * Only for devices the simulator watches. Inventing history for a device a
 * real agent measures would put fiction in front of an engineer, and a gap in
 * real telemetry is a fact about the network.
 *
 * Through the same path as the simulator's live readings, numbered by minute
 * and device, so a re-run stores nothing it already has: the unique key on
 * agent, sequence and time sees it as a duplicate.
 *
 * @see App\Console\Commands\BackfillTelemetry
 * @see App\Services\Telemetry\TelemetryCollector
 */
class TelemetryBackfill
{
    /** Minutes between filled readings. Live collection is every minute; the charts cannot tell. */
    public const STEP_MINUTES = 5;

    /** Readings per store call: a fortnight is hundreds of thousands. */
    private const BATCH = 2000;

    public function __construct(
        private readonly Simulator $simulator,
        private readonly TelemetryService $telemetry,
        private readonly TelemetryStorage $storage,
    ) {}

    /**
     * Fill the silence since collection stopped, so the charts do not come
     * back empty.
     *
     * Nothing happens when the newest reading is fresher than
     * `offline_after_min`, or when nothing was ever collected (that is not a
     * gap). The hourly summaries are refreshed over the filled hours, so the
     * hourly charts get them too.
     *
     * @return array{from: Carbon, written: int}|null
     */
    public function fillGap(): ?array
    {
        // What the simulator measures, the same set a live round reads. A
        // device whose feed is interrupted is not listened to, so its silence
        // is the point and must not be filled in.
        $devices = $this->simulator->watched()->get();

        if ($devices->isEmpty()) {
            return null;
        }

        $from = $this->gapStart($devices);

        if (! $from || $from->diffInMinutes(now()) < (int) config('atlas.thresholds.offline_after_min')) {
            return null;
        }

        $written = $this->write($devices, $from, now())['written'];

        if ($written === 0) {
            return null;
        }

        $this->storage->refreshHourly($from);

        return ['from' => $from, 'written' => $written];
    }

    /**
     * Readings every `$step` minutes from `$from` up to, not including, `$to`.
     *
     * The moments sit on a grid counted from the hour, so a re-run lands on
     * the same moments, and the readings it already stored come back as
     * duplicates. A device's status follows its newest reading only when that
     * is newer than the one it has, so history filled in behind a live
     * reading never overrules it. Status changes here raise nothing: this is
     * history, not news.
     *
     * @param  Collection<int, NetworkDevice>  $devices
     * @param  (Closure(): void)|null  $onStep  called once per moment, for a progress bar
     * @return array{written: int, skipped: int}
     */
    public function write(Collection $devices, CarbonInterface $from, CarbonInterface $to, int $step = self::STEP_MINUTES, ?Closure $onStep = null): array
    {
        $cursor = Carbon::instance($from)->startOfHour();

        while ($cursor->lt($from)) {
            $cursor->addMinutes($step);
        }

        $pending = collect();
        $written = 0;
        $skipped = 0;

        $flush = function () use (&$pending, &$written, &$skipped): void {
            $stored = $this->telemetry->record($pending)->stored->count();
            $written += $stored;
            $skipped += $pending->count() - $stored;
            $pending = collect();
        };

        while ($cursor->lt($to)) {
            $pending = $pending->concat($this->simulator->readingsAt($devices, $cursor));

            if ($pending->count() >= self::BATCH) {
                $flush();
            }

            $cursor->addMinutes($step);

            if ($onStep) {
                $onStep();
            }
        }

        if ($pending->isNotEmpty()) {
            $flush();
        }

        return ['written' => $written, 'skipped' => $skipped];
    }

    /**
     * Where the gap begins: the newest reading from these devices, but never
     * more than `gap_fill_hours` back.
     *
     * @param  Collection<int, NetworkDevice>  $devices
     */
    private function gapStart(Collection $devices): ?Carbon
    {
        $newestReading = TelemetryReading::query()
            ->whereIn('network_device_id', $devices->pluck('id'))
            ->max('recorded_at');

        // Never collected: nothing stopped. The chart says "No readings" itself.
        if ($newestReading === null) {
            return null;
        }

        return now()->subHours((int) config('atlas.gap_fill_hours'))->max(Carbon::parse($newestReading));
    }
}
