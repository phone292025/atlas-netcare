<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Wi-Fi access points in the monitored zones.
 *
 * `status` here is the ADMINISTRATIVE state of the device and may be
 * `maintenance`. That is deliberately a wider set than
 * telemetry_readings.status, which records a MEASURED state and only ever
 * holds online / degraded / offline. The two must not share an enum.
 *
 * @see docs/04-database.md — Cluster A
 * @see docs/progress/decision-log.md — 2026-08-27, telemetry_readings.status
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('access_points', function (Blueprint $table): void {
            $table->id();

            $table->string('ap_code', 40)->unique();
            $table->foreignId('location_id')->constrained()->restrictOnDelete();

            $table->string('model')->nullable();

            // A MAC address identifies the hardware; two records claiming the
            // same one means somebody double-entered a device.
            $table->string('mac_address', 17)->unique();
            $table->string('ip_address', 45)->nullable();

            // Saturation is client_count / client_capacity, one of the three
            // degradation thresholds in config('atlas.thresholds').
            $table->unsignedSmallInteger('client_capacity')->default(50);

            $table->date('installed_at')->nullable();

            $table->string('status', 20)->default('online');

            $table->decimal('risk_score', 5, 2)->nullable();
            $table->string('risk_band', 20)->nullable();

            // How the collector decides an access point has gone offline:
            // no reading for config('atlas.thresholds.offline_after_min').
            $table->timestamp('last_seen_at')->nullable();

            $table->boolean('is_demo')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['location_id', 'status']);
            $table->index('risk_band');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('access_points');
    }
};
