<?php

use App\Enums\NetworkStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Hourly summaries become something the database derives from raw readings
 * (ADR-0011, roadmap task 1.4), instead of a table our code writes.
 *
 * The table could drift from the readings. The demo seeder wrote hourly rows
 * no reading backed, and a wide re-aggregation once threatened to overwrite
 * a month of charts with different numbers. Same name and columns, so
 * everything that reads it keeps working.
 *
 * PostgreSQL: a TimescaleDB continuous aggregate. It stores finished hours,
 * refreshed by a background job, and reads newer hours live from the raw
 * readings (materialized_only = false). `atlas:telemetry-storage` sets the
 * jobs from config every time the app starts. Elsewhere, a plain view: SQLite
 * runs the quick local suite.
 *
 * The rows the old table held go. Seeded hours had no readings behind them,
 * and the rest the aggregate rebuilds from the readings.
 *
 * Outside a transaction, because TimescaleDB won't create a continuous
 * aggregate inside one.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        Schema::drop('telemetry_hourly');

        $offline = NetworkStatus::Offline->value;

        /*
         * Latency averages EXCLUDE offline readings: an unreachable device has
         * no latency, and counting it as zero would make an outage read as its
         * best hour. Clients are the PEAK, because a room at capacity for ten
         * minutes is the fact worth keeping. Uptime is the share of readings
         * that were not offline.
         */
        $measures = "AVG(CASE WHEN status <> '{$offline}' THEN latency_ms END) AS avg_latency_ms,
            MAX(client_count) AS peak_client_count,
            AVG(packet_loss_pct) AS avg_packet_loss_pct,
            AVG(channel_util_pct) AS avg_channel_util_pct,
            100.0 * SUM(CASE WHEN status <> '{$offline}' THEN 1 ELSE 0 END) / COUNT(*) AS uptime_pct,
            COUNT(*) AS reading_count";

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("CREATE MATERIALIZED VIEW telemetry_hourly
                WITH (timescaledb.continuous, timescaledb.materialized_only = false) AS
                SELECT network_device_id, time_bucket(INTERVAL '1 hour', recorded_at) AS hour_bucket, source, {$measures}
                FROM telemetry_readings
                GROUP BY network_device_id, hour_bucket, source
                WITH NO DATA");

            return;
        }

        DB::statement("CREATE VIEW telemetry_hourly AS
            SELECT network_device_id, strftime('%Y-%m-%d %H:00:00', recorded_at) AS hour_bucket, source, {$measures}
            FROM telemetry_readings
            GROUP BY network_device_id, hour_bucket, source");
    }

    public function down(): void
    {
        DB::statement(DB::getDriverName() === 'pgsql'
            ? 'DROP MATERIALIZED VIEW telemetry_hourly'
            : 'DROP VIEW telemetry_hourly');

        // The table as it was, empty: `atlas:aggregate-telemetry` in that
        // version of the code fills it from the readings.
        Schema::create('telemetry_hourly', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('network_device_id')->constrained('network_devices')->cascadeOnDelete();
            $table->dateTime('hour_bucket');
            $table->decimal('avg_latency_ms', 8, 2)->nullable();
            $table->unsignedSmallInteger('peak_client_count')->default(0);
            $table->decimal('avg_packet_loss_pct', 5, 2)->default(0);
            $table->decimal('avg_channel_util_pct', 5, 2)->default(0);
            $table->decimal('uptime_pct', 5, 2)->default(100);
            $table->unsignedInteger('reading_count')->default(0);
            $table->string('source', 20)->default('simulated');
            $table->timestamps();
            $table->unique(['network_device_id', 'hour_bucket']);
            $table->index('hour_bucket');
        });
    }
};
