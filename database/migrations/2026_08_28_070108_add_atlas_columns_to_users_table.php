<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the Atlas NetCare columns to Laravel's stock users table.
 *
 * A separate migration rather than an edit to 0001_01_01_000000_create_users_
 * table.php, because that one is already merged and six other people have run
 * it. Editing a merged migration silently breaks their local databases — hard
 * rule 4.
 *
 * @see docs/04-database.md — Cluster A
 * @see docs/modules/M1-identity-and-audit.md
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            // TP number is how the university identifies a student, and how a
            // marker will recognise a real account. Nullable because staff
            // engineers do not have one.
            $table->string('tp_number', 20)->nullable()->unique()->after('id');

            // A string column cast to App\Enums\UserRole, never a MySQL ENUM —
            // adding a role stays a code change. See ADR-0004.
            $table->string('role', 20)->nullable()->after('email');

            $table->string('phone', 30)->nullable()->after('role');

            // FR-106: deactivation removes access but preserves history, so
            // this is never a delete.
            $table->boolean('is_active')->default(true)->after('phone');

            // Q6 is unresolved. The column exists because docs/04-database.md
            // specifies it; NO first-login enforcement is built until the team
            // ratifies Q6. See docs/progress/decision-log.md.
            $table->boolean('must_change_password')->default(false)->after('is_active');

            // Set only by DemoDataSeeder, so atlas:purge-demo-data can find
            // seeded accounts and leave real ones alone. See ADR-0003.
            $table->boolean('is_demo')->default(false)->after('must_change_password');

            // Retiring a person must not erase the inspections they carried
            // out or the tickets they raised.
            $table->softDeletes();

            $table->index(['role', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['role', 'is_active']);
            $table->dropSoftDeletes();
            $table->dropColumn([
                'tp_number', 'role', 'phone',
                'is_active', 'must_change_password', 'is_demo',
            ]);
        });
    }
};
