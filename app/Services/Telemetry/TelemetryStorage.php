<?php

namespace App\Services\Telemetry;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * The TimescaleDB jobs that look after telemetry (ADR-0011).
 *
 * Raw readings are a hypertable, and `telemetry_hourly` is a continuous
 * aggregate the database derives from them. Four background jobs keep them:
 * compression and retention for the readings, and refresh and retention for
 * the hourly summaries. Their settings come from `config('atlas.retention')`,
 * applied by `atlas:telemetry-storage` every time the app starts.
 *
 * Everything here does nothing off TimescaleDB. SQLite, which runs the quick
 * local suite, has a plain view for the hourly summaries, always current.
 *
 * @see App\Console\Commands\TelemetryStorage
 * @see docs/04-database.md
 */
class TelemetryStorage
{
    public function onTimescale(): bool
    {
        return DB::getDriverName() === 'pgsql'
            && DB::selectOne("SELECT 1 AS ok FROM pg_extension WHERE extname = 'timescaledb'") !== null;
    }

    /**
     * Set the four jobs from config, replacing any already set, so a changed
     * value takes effect. Safe to repeat.
     *
     * @return list<string> what each job now does, for the command to print
     */
    public function applyPolicies(): array
    {
        if (! $this->onTimescale()) {
            return [];
        }

        $retention = config('atlas.retention');
        $compress = (int) $retention['compress_after_days'];
        $raw = (int) $retention['raw_days'];
        $months = (int) $retention['aggregate_months'];
        $late = (int) $retention['late_reading_days'];
        $every = (int) $retention['hourly_refresh_minutes'];

        DB::statement("SELECT remove_compression_policy('telemetry_readings', if_exists => true)");
        DB::statement("SELECT add_compression_policy('telemetry_readings', compress_after => INTERVAL '{$compress} days')");

        DB::statement("SELECT remove_retention_policy('telemetry_readings', if_exists => true)");
        DB::statement("SELECT add_retention_policy('telemetry_readings', drop_after => INTERVAL '{$raw} days')");

        // The refresh looks back far enough for a late reading to land in its
        // hour, and stops an hour short of now: the newest hour is read live
        // from the raw readings instead.
        DB::statement("SELECT remove_continuous_aggregate_policy('telemetry_hourly', if_exists => true)");
        DB::statement("SELECT add_continuous_aggregate_policy('telemetry_hourly', start_offset => INTERVAL '{$late} days', end_offset => INTERVAL '1 hour', schedule_interval => INTERVAL '{$every} minutes')");

        DB::statement("SELECT remove_retention_policy('telemetry_hourly', if_exists => true)");
        DB::statement("SELECT add_retention_policy('telemetry_hourly', drop_after => INTERVAL '{$months} months')");

        return [
            "Raw readings compressed after {$compress} days and dropped after {$raw}.",
            "Hourly summaries refreshed every {$every} minutes over the last {$late} days, and kept {$months} months.",
        ];
    }

    /**
     * Bring the hourly summaries up to date from `$from`, or over all time.
     *
     * Needed after writing readings older than the refresh window looks: a
     * seeded fortnight, a backfill, a purge. Without it those hours stay
     * missing from the aggregate, because only hours newer than the last one
     * it stored are read live from the raw readings.
     *
     * TimescaleDB can't refresh inside a transaction, so inside one this does
     * nothing. That is every test: they write nothing an earlier refresh
     * stored, so the aggregate reads all of it live.
     */
    public function refreshHourly(?CarbonInterface $from = null): void
    {
        if (! $this->onTimescale() || DB::transactionLevel() > 0) {
            return;
        }

        // Up to an hour ago, where the background refresh also stops. Storing
        // the hour still being written would hide its later readings until
        // the hour was old enough for the background job to redo it.
        DB::statement(
            "CALL refresh_continuous_aggregate('telemetry_hourly', ?::timestamp, ?::timestamp)",
            [$from?->copy()->startOfHour()->toDateTimeString(), now()->subHour()->startOfHour()->toDateTimeString()],
        );
    }
}
