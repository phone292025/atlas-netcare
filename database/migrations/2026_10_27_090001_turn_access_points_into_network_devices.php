<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Access points become one kind of network device (ADR-0016).
 *
 * Roadmap task 1.1 brings switches, routers and servers, each with an
 * optional parent: the switch an access point plugs into. They share
 * everything that makes an access point monitorable, from its status to its
 * readings, so they share its table.
 *
 * Renamed, not copied, so every id stays. Readings, hourly rows, tickets,
 * risk assessments, alarms and audit rows all point at these ids, and none of
 * them has to change. `ap_code` becomes `code`, because SW-01 is not an AP code.
 *
 * `mac_address` and `client_capacity` become nullable: a server watched by
 * ping has no MAC on record, and only an access point has clients. Existing
 * rows keep their values. `kind` gets no default, so a row that doesn't say
 * what it is fails to insert instead of quietly becoming an access point.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::rename('access_points', 'network_devices');

        Schema::table('network_devices', function (Blueprint $table): void {
            $table->renameColumn('ap_code', 'code');
        });

        Schema::table('network_devices', function (Blueprint $table): void {
            $table->renameIndex('access_points_ap_code_unique', 'network_devices_code_unique');
            $table->renameIndex('access_points_mac_address_unique', 'network_devices_mac_address_unique');
            $table->renameIndex('access_points_location_id_status_index', 'network_devices_location_id_status_index');
            $table->renameIndex('access_points_risk_band_index', 'network_devices_risk_band_index');

            // Every existing row is an access point. The default goes again below.
            $table->string('kind', 20)->default('access_point');
            $table->string('name', 120)->nullable();
            $table->foreignId('parent_id')->nullable()->constrained('network_devices')->nullOnDelete();
            $table->index('parent_id');
        });

        Schema::table('network_devices', function (Blueprint $table): void {
            $table->string('kind', 20)->change();
            $table->string('mac_address', 17)->nullable()->change();
            $table->unsignedSmallInteger('client_capacity')->nullable()->change();
        });

        // PostgreSQL keeps the old names on the constraints and the id
        // sequence. A later migration that drops one by its usual name would
        // look for network_devices_* and fail.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE network_devices RENAME CONSTRAINT access_points_pkey TO network_devices_pkey');
            DB::statement('ALTER TABLE network_devices RENAME CONSTRAINT access_points_location_id_foreign TO network_devices_location_id_foreign');
            DB::statement('ALTER SEQUENCE access_points_id_seq RENAME TO network_devices_id_seq');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER SEQUENCE network_devices_id_seq RENAME TO access_points_id_seq');
            DB::statement('ALTER TABLE network_devices RENAME CONSTRAINT network_devices_location_id_foreign TO access_points_location_id_foreign');
            DB::statement('ALTER TABLE network_devices RENAME CONSTRAINT network_devices_pkey TO access_points_pkey');
        }

        // Anything but an access point has nowhere to go in the old table.
        DB::table('network_devices')->where('kind', '!=', 'access_point')->delete();

        Schema::table('network_devices', function (Blueprint $table): void {
            $table->dropForeign(['parent_id']);
            $table->dropIndex(['parent_id']);
            $table->dropColumn(['kind', 'name', 'parent_id']);

            $table->string('mac_address', 17)->nullable(false)->change();
            $table->unsignedSmallInteger('client_capacity')->default(50)->change();

            $table->renameIndex('network_devices_code_unique', 'access_points_ap_code_unique');
            $table->renameIndex('network_devices_mac_address_unique', 'access_points_mac_address_unique');
            $table->renameIndex('network_devices_location_id_status_index', 'access_points_location_id_status_index');
            $table->renameIndex('network_devices_risk_band_index', 'access_points_risk_band_index');
        });

        Schema::table('network_devices', function (Blueprint $table): void {
            $table->renameColumn('code', 'ap_code');
        });

        Schema::rename('network_devices', 'access_points');
    }
};
