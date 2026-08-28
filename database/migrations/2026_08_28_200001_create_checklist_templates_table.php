<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A named, versioned set of checks for inspecting a lab PC.
 *
 * VERSIONED, NOT EDITED IN PLACE (Q2). An inspection stores the template id it
 * was completed against, so editing an active template would silently rewrite
 * history — a six-month-old inspection would render against items that did not
 * exist when it was carried out, or lose items that did. Editing an active
 * template therefore copies it to version + 1 and deactivates the old one,
 * which stays in the database forever so old inspections still render.
 *
 * @see docs/06-database.md
 * @see docs/04-open-questions.md Q2
 * @see docs/modules/M3-checklists-and-inspections.md
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('checklist_templates', function (Blueprint $table): void {
            $table->id();

            $table->string('name');
            $table->text('description')->nullable();

            $table->unsignedInteger('version')->default(1);

            /*
             * Exactly one template should be active at a time in practice, but
             * that is a rule the service enforces, not the database. A partial
             * unique index would say it better and MariaDB does not have one;
             * a plain unique on is_active would forbid a second *inactive*
             * template, which is precisely what versioning produces.
             */
            $table->boolean('is_active')->default(false);

            $table->timestamps();

            // "The active template" and "every version of this name" are the
            // only two ways this table is ever read.
            $table->index('is_active');
            $table->index(['name', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checklist_templates');
    }
};
