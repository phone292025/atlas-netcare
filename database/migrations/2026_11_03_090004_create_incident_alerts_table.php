<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every alert an incident sends: one row per incident, person, escalation
 * step and channel (roadmap 2.6).
 *
 * The unique key is the claim. An alert is inserted before it is sent, and a
 * retry, a second worker or a second sweep finds the row and sends nothing.
 * `sent_at` is set when the channel took it; `failed_at` and `error` when
 * every retry failed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('incident_alerts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('incident_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('step');
            $table->string('channel', 20);
            $table->dateTime('sent_at')->nullable();
            $table->dateTime('failed_at')->nullable();
            $table->string('error')->nullable();
            $table->timestamps();

            $table->unique(['incident_id', 'user_id', 'step', 'channel'], 'incident_alerts_once_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incident_alerts');
    }
};
