<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The simulator becomes an agent (ADR-0010, roadmap task 1.5), so the devices
 * it measured need to be watched by it.
 *
 * Before agents, the simulated driver measured every access point. Now a
 * collection round measures only what the simulator's virtual agent watches,
 * so without this an existing installation would stop getting readings. The
 * demo's switches and router go with them, so a migrated database matches a
 * fresh seed. A device already given to a real agent keeps it.
 *
 * The virtual agent is made only when there is something to hand it: a fresh
 * installation gets one when the demo data is seeded, and a clean one never
 * does.
 */
return new class extends Migration
{
    public function up(): void
    {
        $unwatched = DB::table('network_devices')
            ->whereNull('agent_id')
            ->where(fn ($query) => $query->where('kind', 'access_point')->orWhere('is_demo', true));

        if (! $unwatched->exists()) {
            return;
        }

        $simulator = DB::table('agents')->where('is_virtual', true)->whereNull('deleted_at')->value('id')
            ?? DB::table('agents')->insertGetId([
                'name' => 'Simulator',
                'is_virtual' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

        $unwatched->update(['agent_id' => $simulator]);
    }

    /**
     * Unwatched again. The virtual agent stays: its readings point at it, and
     * it is what the code makes on first use anyway.
     */
    public function down(): void
    {
        DB::table('network_devices')
            ->whereIn('agent_id', DB::table('agents')->where('is_virtual', true)->select('id'))
            ->update(['agent_id' => null]);
    }
};
