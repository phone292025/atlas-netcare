<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Readings and hourly rows belong to a network device, not only an access
 * point (ADR-0016). Switches and routers send readings from roadmap task 1.3,
 * and `access_point_id` on a switch's reading would be wrong.
 *
 * A rename, so every row keeps pointing at the same device. The foreign keys
 * already point at network_devices, since that table was renamed.
 */
return new class extends Migration
{
    private const RENAMES = [
        'telemetry_readings' => [
            'telemetry_readings_access_point_id_recorded_at_index' => 'telemetry_readings_network_device_id_recorded_at_index',
        ],
        'telemetry_hourly' => [
            'telemetry_hourly_access_point_id_hour_bucket_unique' => 'telemetry_hourly_network_device_id_hour_bucket_unique',
        ],
    ];

    public function up(): void
    {
        $this->rename('access_point_id', 'network_device_id', fn (string $old, string $new) => [$old, $new]);
    }

    public function down(): void
    {
        $this->rename('network_device_id', 'access_point_id', fn (string $old, string $new) => [$new, $old]);
    }

    /** @param  callable(string, string): array{string, string}  $direction */
    private function rename(string $from, string $to, callable $direction): void
    {
        foreach (self::RENAMES as $table => $indexes) {
            Schema::table($table, function (Blueprint $blueprint) use ($from, $to): void {
                $blueprint->renameColumn($from, $to);
            });

            Schema::table($table, function (Blueprint $blueprint) use ($indexes, $direction): void {
                foreach ($indexes as $old => $new) {
                    $blueprint->renameIndex(...$direction($old, $new));
                }
            });

            // PostgreSQL keeps a constraint's name through a column rename.
            if (DB::getDriverName() === 'pgsql') {
                DB::statement("ALTER TABLE {$table} RENAME CONSTRAINT {$table}_{$from}_foreign TO {$table}_{$to}_foreign");
            }
        }
    }
};
