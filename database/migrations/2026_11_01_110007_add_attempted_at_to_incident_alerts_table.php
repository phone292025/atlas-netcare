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
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('incident_alerts', function (Blueprint $table): void {
            $table->dateTime('attempted_at')->nullable()->after('channel');
        });
    }

    public function down(): void
    {
        Schema::table('incident_alerts', function (Blueprint $table): void {
            $table->dropColumn('attempted_at');
        });
    }
};
