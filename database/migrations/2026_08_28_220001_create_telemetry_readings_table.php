<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One measurement of one access point at one moment.
 *
 * THE ONLY HIGH-VOLUME TABLE IN THE SYSTEM. Forty access points at one
 * reading a minute is roughly 1.7 million rows a month, which drives three
 * decisions here:
 *
 *   - no timestamps(). `recorded_at` is the only time that means anything;
 *     created_at and updated_at would be two more datetimes on every row for
 *     nothing. This is the one table in the schema exempt from the convention.
 *   - the smallest column type that fits each measurement.
 *   - one composite index, matching the only query anybody runs.
 *
 * `status` here is MEASURED state and only ever holds online / degraded /
 * offline. It must NOT be cast to ApStatus, which also has `maintenance` — an
 * administrative state that belongs to the access point, not to a reading.
 *
 * @see docs/06-database.md
 * @see docs/decisions/ADR-0002-telemetry-source-driver.md
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telemetry_readings', function (Blueprint $table): void {
            $table->id();

            // cascadeOnDelete: readings are owned by their access point and
            // mean nothing without it. An AP is soft-deleted rather than
            // removed, so this only fires on a genuine purge.
            $table->foreignId('access_point_id')->constrained()->cascadeOnDelete();

            $table->timestamp('recorded_at');

            $table->unsignedSmallInteger('latency_ms')->nullable();
            $table->unsignedSmallInteger('client_count')->default(0);
            $table->decimal('packet_loss_pct', 5, 2)->default(0);
            $table->decimal('channel_util_pct', 5, 2)->default(0);
            $table->decimal('throughput_mbps', 8, 2)->default(0);

            $table->string('status', 20);

            /*
             * `simulated` or `live`. Both kinds sit in one table, which is
             * what lets the driver be swapped without a second database and
             * lets simulated rows be charted or purged separately.
             *
             * See ADR-0002 and docs/07-data-strategy.md.
             */
            $table->string('source', 20)->default('simulated');

            // Every chart query and the aggregation sweep read exactly this.
            $table->index(['access_point_id', 'recorded_at']);

            // The pruning command sweeps by age across all access points.
            $table->index('recorded_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telemetry_readings');
    }
};
