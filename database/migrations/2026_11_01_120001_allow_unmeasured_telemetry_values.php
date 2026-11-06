<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A reading can say it didn't measure something.
 *
 * A ping-only or HTTP-only probe has no client count, channel use or
 * throughput, and an SNMP probe no packet loss. Until now those were stored
 * as zero, so a ping-only switch showed no clients and no traffic, and an
 * hour's averages counted the zeros. Null means "not measured": AVG and MAX
 * skip it, the screens show a dash, and the classifier judges only what was
 * measured.
 *
 * The defaults stay, so an old reading and the simulator are unchanged.
 */
return new class extends Migration
{
    /** @var list<string> */
    private array $columns = ['client_count', 'packet_loss_pct', 'channel_util_pct', 'throughput_mbps'];

    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            // Dropping NOT NULL changes no type, so the hourly aggregate on
            // top of the table can stay.
            foreach ($this->columns as $column) {
                DB::statement("ALTER TABLE telemetry_readings ALTER COLUMN {$column} DROP NOT NULL");
            }

            return;
        }

        $this->aroundTheHourlyView(fn () => Schema::table('telemetry_readings', function (Blueprint $table): void {
            $table->unsignedSmallInteger('client_count')->nullable()->default(0)->change();
            $table->decimal('packet_loss_pct', 5, 2)->nullable()->default(0)->change();
            $table->decimal('channel_util_pct', 5, 2)->nullable()->default(0)->change();
            $table->decimal('throughput_mbps', 8, 2)->nullable()->default(0)->change();
        }));
    }

    public function down(): void
    {
        foreach ($this->columns as $column) {
            DB::table('telemetry_readings')->whereNull($column)->update([$column => 0]);
        }

        if (DB::getDriverName() === 'pgsql') {
            foreach ($this->columns as $column) {
                DB::statement("ALTER TABLE telemetry_readings ALTER COLUMN {$column} SET NOT NULL");
            }

            return;
        }

        $this->aroundTheHourlyView(fn () => Schema::table('telemetry_readings', function (Blueprint $table): void {
            $table->unsignedSmallInteger('client_count')->default(0)->change();
            $table->decimal('packet_loss_pct', 5, 2)->default(0)->change();
            $table->decimal('channel_util_pct', 5, 2)->default(0)->change();
            $table->decimal('throughput_mbps', 8, 2)->default(0)->change();
        }));
    }

    /**
     * SQLite changes a column by rebuilding the table, and refuses while a
     * view names it. The view goes for the rebuild and comes back exactly as
     * it was, from its own stored SQL.
     */
    private function aroundTheHourlyView(Closure $change): void
    {
        $view = DB::table('sqlite_master')->where(['type' => 'view', 'name' => 'telemetry_hourly'])->value('sql');

        DB::statement('DROP VIEW IF EXISTS telemetry_hourly');
        $change();

        if ($view) {
            DB::statement($view);
        }
    }
};
