<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What the ingest API needs to store a reading once and in its place
 * (ADR-0017, roadmap task 1.3).
 *
 * - `agent_id` and `sequence`: an agent numbers its readings, and retries a
 *   batch whose answer it never got. The unique key stores each one once. It
 *   includes `recorded_at` because every unique key on a hypertable must, and a
 *   retried reading carries the same time. Readings from before agents have
 *   neither, and nulls never collide.
 * - `received_at`: when Atlas got it, beside `recorded_at`, when the agent
 *   measured it. A buffered reading can arrive hours after it was taken.
 * - `network_devices.last_reading_at`: the newest reading's time, so a late
 *   reading fills in history without moving a device's status backwards.
 *   `last_seen_at` can't do it: it only moves when the device answered.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telemetry_readings', function (Blueprint $table): void {
            $agent = $table->foreignId('agent_id')->nullable();

            // No cascade: retiring an agent is a soft delete, and its readings
            // stay with it. Not on SQLite, where adding a foreign key rebuilds
            // the table, and the rebuild fails on the telemetry_hourly view
            // that reads it. SQLite only runs the quick local suite.
            if (DB::getDriverName() !== 'sqlite') {
                $agent->constrained('agents');
            }

            $table->unsignedBigInteger('sequence')->nullable();
            $table->dateTime('received_at')->nullable();

            $table->unique(['agent_id', 'sequence', 'recorded_at']);
        });

        Schema::table('network_devices', function (Blueprint $table): void {
            $table->dateTime('last_reading_at')->nullable();
        });

        DB::table('network_devices')->update([
            'last_reading_at' => DB::raw('(SELECT MAX(recorded_at) FROM telemetry_readings WHERE telemetry_readings.network_device_id = network_devices.id)'),
        ]);
    }

    public function down(): void
    {
        Schema::table('network_devices', function (Blueprint $table): void {
            $table->dropColumn('last_reading_at');
        });

        Schema::table('telemetry_readings', function (Blueprint $table): void {
            $table->dropUnique(['agent_id', 'sequence', 'recorded_at']);

            if (DB::getDriverName() !== 'sqlite') {
                $table->dropForeign(['agent_id']);
            }

            $table->dropColumn(['agent_id', 'sequence', 'received_at']);
        });
    }
};
