<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When a worker last took an alert to send it.
 *
 * A worker sets it before sending, so a second job for the same alert sends
 * nothing while the first is at it. It also lets the sweep find an alert
 * whose job never reached the queue, or whose worker died mid-send, and queue
 * it again (IncidentAlerts::requeueStalled).
 *
 * `maybe_sent` marks an alert that may have reached its person already: a
 * worker died holding it, or an attempt timed out. Its next send is labelled
 * a repeat.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('incident_alerts', function (Blueprint $table): void {
            $table->dateTime('attempted_at')->nullable()->after('channel');
            $table->boolean('maybe_sent')->default(false)->after('attempted_at');
        });
    }

    public function down(): void
    {
        Schema::table('incident_alerts', function (Blueprint $table): void {
            $table->dropColumn(['attempted_at', 'maybe_sent']);
        });
    }
};
