<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One hour of readings for one access point, rolled up.
 *
 * CHARTS READ THIS TABLE, NEVER telemetry_readings. Twenty-four hours of raw
 * data for one access point is 1,440 rows; the same period here is 24. NFR-02
 * wants the detail chart in under two seconds, and scanning raw readings for
 * it is visibly slow during a demo — which is the moment it would be noticed.
 *
 * @see docs/04-database.md
 * @see docs/02-open-questions.md Q9
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telemetry_hourly', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('access_point_id')->constrained()->cascadeOnDelete();

            // The hour this summarises, truncated to :00:00.
            $table->timestamp('hour_bucket');

            $table->decimal('avg_latency_ms', 8, 2)->nullable();
            $table->unsignedSmallInteger('peak_client_count')->default(0);
            $table->decimal('avg_packet_loss_pct', 5, 2)->default(0);
            $table->decimal('avg_channel_util_pct', 5, 2)->default(0);

            /*
             * Share of this hour's readings that were not offline. This is the
             * figure the report quotes as "uptime", and it is why aggregation
             * cannot simply average the raw numbers and discard the rest.
             */
            $table->decimal('uptime_pct', 5, 2)->default(100);

            // How many raw readings this row summarises. An hour built from
            // four readings is not as trustworthy as one built from sixty, and
            // without this the chart cannot say so.
            $table->unsignedInteger('reading_count')->default(0);

            $table->string('source', 20)->default('simulated');

            $table->timestamps();

            /*
             * Unique, not just indexed. Aggregation is re-runnable by design —
             * it is a scheduled command that may overlap or be run by hand —
             * and without this a second pass over the same hour silently
             * doubles every point on the chart.
             */
            $table->unique(['access_point_id', 'hour_bucket']);

            $table->index('hour_bucket');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telemetry_hourly');
    }
};
