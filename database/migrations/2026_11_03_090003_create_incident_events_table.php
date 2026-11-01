<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An incident's timeline: opened, devices joining, alerts, escalation,
 * acknowledgement, the ticket, resolution. Written by IncidentService only,
 * never edited.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('incident_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('incident_id')->constrained()->cascadeOnDelete();
            $table->string('type', 30);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('message');
            $table->dateTime('happened_at');
            $table->timestamps();

            $table->index(['incident_id', 'happened_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incident_events');
    }
};
