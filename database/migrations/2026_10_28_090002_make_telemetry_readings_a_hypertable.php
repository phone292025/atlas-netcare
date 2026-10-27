<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Raw readings become a TimescaleDB hypertable (ADR-0011, roadmap task 1.4).
 *
 * A hypertable stores rows in time chunks, so old data is compressed and
 * dropped a chunk at a time instead of row by row. Every unique index on one
 * must include the time column, so the `id` column goes: no code looks a
 * reading up by it, and the newest reading per device is found by time.
 *
 * Rebuilt rather than altered, because SQLite can't drop a primary key column:
 * the old table is renamed, the new one created, the rows copied across and
 * the old table dropped. On PostgreSQL the new table becomes a hypertable
 * before the copy, so the rows land in chunks. Elsewhere it stays a plain
 * table: SQLite runs the quick local suite, and has no TimescaleDB.
 */
return new class extends Migration
{
    private const COLUMNS = [
        'network_device_id', 'recorded_at', 'latency_ms', 'client_count', 'packet_loss_pct',
        'channel_util_pct', 'throughput_mbps', 'status', 'source',
    ];

    public function up(): void
    {
        $this->setAside();

        Schema::create('telemetry_readings', function (Blueprint $table): void {
            // cascadeOnDelete: readings are owned by their device and mean
            // nothing without it. Devices are soft-deleted, so this only
            // fires on a genuine purge.
            $table->foreignId('network_device_id')->constrained('network_devices')->cascadeOnDelete();
            $table->dateTime('recorded_at');
            $table->unsignedSmallInteger('latency_ms')->nullable();
            $table->unsignedSmallInteger('client_count')->default(0);
            $table->decimal('packet_loss_pct', 5, 2)->default(0);
            $table->decimal('channel_util_pct', 5, 2)->default(0);
            $table->decimal('throughput_mbps', 8, 2)->default(0);
            // A MEASURED state: online, degraded or offline. Not cast to
            // NetworkStatus, which also carries maintenance.
            $table->string('status', 20);
            $table->string('source', 20)->default('simulated');

            // Every chart query and the newest-reading lookup read exactly this.
            $table->index(['network_device_id', 'recorded_at']);
            $table->index('recorded_at');
        });

        if (DB::getDriverName() === 'pgsql') {
            $days = (int) config('atlas.retention.chunk_days');

            DB::statement("SELECT create_hypertable('telemetry_readings', by_range('recorded_at', INTERVAL '{$days} days'), create_default_indexes => false)");

            // One device's readings stored together and newest first, the
            // order every chart reads them in.
            DB::statement("ALTER TABLE telemetry_readings SET (timescaledb.compress, timescaledb.compress_segmentby = 'network_device_id', timescaledb.compress_orderby = 'recorded_at DESC')");
        }

        $this->copyBack();
    }

    public function down(): void
    {
        $this->setAside();

        Schema::create('telemetry_readings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('network_device_id')->constrained('network_devices')->cascadeOnDelete();
            $table->dateTime('recorded_at');
            $table->unsignedSmallInteger('latency_ms')->nullable();
            $table->unsignedSmallInteger('client_count')->default(0);
            $table->decimal('packet_loss_pct', 5, 2)->default(0);
            $table->decimal('channel_util_pct', 5, 2)->default(0);
            $table->decimal('throughput_mbps', 8, 2)->default(0);
            $table->string('status', 20);
            $table->string('source', 20)->default('simulated');
            $table->index(['network_device_id', 'recorded_at']);
            $table->index('recorded_at');
        });

        $this->copyBack();
    }

    /**
     * Move the current table out of the way. Its indexes go first: index
     * names are global on PostgreSQL, and the new table reuses them.
     */
    private function setAside(): void
    {
        Schema::table('telemetry_readings', function (Blueprint $table): void {
            $table->dropIndex(['network_device_id', 'recorded_at']);
            $table->dropIndex(['recorded_at']);
        });

        Schema::rename('telemetry_readings', 'telemetry_readings_previous');
    }

    /** Copy every row into the new table, in time order, and drop the old one. */
    private function copyBack(): void
    {
        $columns = implode(', ', self::COLUMNS);

        DB::statement("INSERT INTO telemetry_readings ({$columns}) SELECT {$columns} FROM telemetry_readings_previous ORDER BY recorded_at");

        Schema::drop('telemetry_readings_previous');
    }
};
