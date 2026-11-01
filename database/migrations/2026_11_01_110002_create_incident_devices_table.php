<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The devices one incident covers (roadmap 2.3).
 *
 * An offline incident opens on the highest failed device, and the devices
 * behind it that fail with it join it here rather than opening their own.
 * The incident's own device is a row too, so "what is down in this incident"
 * is one query. `recovered_at` is when that device came back.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('incident_devices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('incident_id')->constrained()->cascadeOnDelete();
            $table->foreignId('network_device_id')->constrained()->cascadeOnDelete();
            $table->dateTime('joined_at');
            $table->dateTime('recovered_at')->nullable();
            $table->timestamps();

            $table->unique(['incident_id', 'network_device_id']);
            $table->index('network_device_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incident_devices');
    }
};
