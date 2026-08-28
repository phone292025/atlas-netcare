<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One answer to one checklist item within one inspection.
 *
 * @see docs/06-database.md
 * @see docs/modules/M3-checklists-and-inspections.md
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inspection_results', function (Blueprint $table): void {
            $table->id();

            // Owned by the inspection: deleting an in-progress inspection
            // takes its answers with it, which is the wanted behaviour.
            $table->foreignId('inspection_id')->constrained()->cascadeOnDelete();

            // restrictOnDelete: an item that has been answered cannot be
            // deleted. This is why editing an active template versions it
            // instead of mutating it — the old items must survive.
            $table->foreignId('checklist_item_id')->constrained()->restrictOnDelete();

            $table->string('result', 20);
            $table->text('remark')->nullable();

            $table->timestamps();

            /*
             * One answer per item per inspection, enforced by the database.
             * Without this, a double-submitted form — a phone on bad campus
             * Wi-Fi, which is the normal case here — writes every answer
             * twice and the derived outcome counts the same issue repeatedly.
             */
            $table->unique(['inspection_id', 'checklist_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inspection_results');
    }
};
