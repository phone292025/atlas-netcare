<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Close the loop M3 left open.
 *
 * `inspections.shift_id` has existed since M3 because the ERD has it, but it
 * carried no constraint — `shifts` did not exist yet, and you cannot reference
 * a table that has not been created. M3's migration says in as many words that
 * M6 adds this.
 *
 * nullOnDelete rather than cascade: deleting a shift must not delete the
 * evidence that somebody inspected a machine during it. The inspection is the
 * record that matters; which shift it happened on is context.
 *
 * @see database/migrations/2026_08_28_200003_create_inspections_table.php
 * @see docs/06-database.md
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inspections', function (Blueprint $table): void {
            $table->foreign('shift_id')->references('id')->on('shifts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('inspections', function (Blueprint $table): void {
            $table->dropForeign(['shift_id']);
        });
    }
};
