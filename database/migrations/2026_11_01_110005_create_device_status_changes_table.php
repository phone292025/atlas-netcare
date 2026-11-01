<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Each time a device's status changed, from the DeviceStatusesChanged event.
 *
 * The flapping rule counts these: a device changing status too often in a
 * window is one incident, not an alert each time. Old rows are removed by
 * the incident sweep after `atlas.incidents.history_days`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_status_changes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('network_device_id')->constrained()->cascadeOnDelete();
            $table->string('from_status', 20);
            $table->string('to_status', 20);
            $table->dateTime('changed_at');

            $table->index(['network_device_id', 'changed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_status_changes');
    }
};
