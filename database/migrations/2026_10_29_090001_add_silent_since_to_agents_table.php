<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * When Atlas noticed that a probe agent stopped reporting (roadmap task 1.6).
 *
 * Whether an agent is silent follows from `last_seen_at` and the clock, so
 * that needs no column. This one records that the silence was NOTICED: the
 * engineers were alerted, and its devices turned unknown. Set once per
 * silence, and cleared by the agent's next request, so one silence raises
 * one alert however long it lasts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agents', function (Blueprint $table): void {
            $table->dateTime('silent_since')->nullable()->after('last_seen_at');
        });
    }

    /**
     * Also clears the two values this change writes and the code before it
     * can't read: its enums would throw on them. An unknown device goes back
     * to online until its next reading says otherwise, and the silent-agent
     * alerts go.
     */
    public function down(): void
    {
        DB::table('network_devices')->where('status', 'unknown')->update(['status' => 'online']);
        DB::table('alarms')->where('type', 'agent_silent')->delete();

        Schema::table('agents', function (Blueprint $table): void {
            $table->dropColumn('silent_since');
        });
    }
};
