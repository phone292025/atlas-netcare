<?php

use App\Enums\SimulationScenario;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which condition the simulated driver is forcing on an access point, if any.
 *
 * Demonstration tooling for the Network monitor's simulation controls: an
 * engineer can make one access point, or all of them, congested, unreachable
 * or silent, and watch the rest of the system react exactly as it would to the
 * real thing — classification, auto-tickets, alarms.
 *
 * A string cast to SimulationScenario, not a MySQL ENUM (ADR-0004). NULL means
 * normal behaviour. `simulation_started_at` is `dateTime`, not `timestamp`, for
 * the reason docs/04-database.md gives: MariaDB silently rewrites the first
 * `timestamp` column in a table on every UPDATE.
 *
 * Written only by SimulationService, never from request input, so neither
 * column is in AccessPoint::$fillable.
 *
 * @see SimulationScenario
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('access_points', function (Blueprint $table): void {
            $table->string('simulation_scenario', 32)->nullable()->after('status');
            $table->dateTime('simulation_started_at')->nullable()->after('simulation_scenario');
        });
    }

    public function down(): void
    {
        Schema::table('access_points', function (Blueprint $table): void {
            $table->dropColumn(['simulation_scenario', 'simulation_started_at']);
        });
    }
};
