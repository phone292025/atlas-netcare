<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One question on a checklist template.
 *
 * @see docs/04-database.md
 * @see docs/modules/M3-checklists-and-inspections.md
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('checklist_items', function (Blueprint $table): void {
            $table->id();

            /*
             * cascadeOnDelete: items are owned by their template and have no
             * meaning without it. Deleting a template is only ever possible
             * while it has never been used — an inspection restricts the
             * delete — so this cascade can never take items out from under a
             * historical inspection.
             */
            $table->foreignId('checklist_template_id')->constrained()->cascadeOnDelete();

            $table->string('label');
            $table->string('category', 20)->default('boot');

            /*
             * A critical item failing makes the whole inspection a `fail`
             * rather than `pass_with_issues`. This one boolean is the entire
             * difference between the two outcomes.
             */
            $table->boolean('is_critical')->default(false);

            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();

            // The inspection form reads exactly this: one template's items,
            // in order.
            $table->index(['checklist_template_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checklist_items');
    }
};
