<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which checklist answer a ticket was raised from (FR-207).
 *
 * `inspection_id` says which inspection; this says which failed item. Without
 * it nothing could tell that a fault had already been ticketed, and the same
 * failed item could be raised again from the same inspection, with a second
 * ticket in the queue for one fault.
 *
 * nullOnDelete, like `inspection_id`: removing an inspection must not remove
 * the ticket it produced.
 *
 * @see docs/modules/M4-ticketing.md
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table): void {
            $table->foreignId('inspection_result_id')->nullable()->after('inspection_id')
                ->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('inspection_result_id');
        });
    }
};
