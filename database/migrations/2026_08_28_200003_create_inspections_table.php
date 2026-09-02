<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One assistant completing one checklist against one lab PC.
 *
 * @see docs/04-database.md
 * @see docs/modules/M3-checklists-and-inspections.md
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inspections', function (Blueprint $table): void {
            $table->id();

            /*
             * restrictOnDelete throughout. An inspection is the evidence that
             * somebody checked a machine on a date — it must outlive the
             * machine being retired and the assistant leaving.
             *
             * M2 retires a lab PC by soft-deleting it, which does not touch
             * these rows; the constraint is here to stop a hard delete taken
             * later from erasing the history M7 scores on.
             */
            $table->foreignId('lab_pc_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('checklist_template_id')->constrained()->restrictOnDelete();

            /*
             * M6 owns `shifts`, which does not exist yet, so the column is
             * here (the ERD has it) but the foreign key is not — you cannot
             * reference a table that has not been created.
             *
             * M6's migration adds the constraint:
             *   $table->foreign('shift_id')->references('id')->on('shifts')->nullOnDelete();
             *
             * Until then nothing writes it and it stays null.
             */
            $table->unsignedBigInteger('shift_id')->nullable();

            // Derived by InspectionService, never entered. Null while the
            // inspection is still in progress.
            $table->string('overall_result', 20)->nullable();

            $table->text('notes')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();

            // "This machine's inspection history, newest first" — the show
            // page, and M7's scoring input. Named in docs/04-database.md.
            $table->index(['lab_pc_id', 'completed_at']);

            // "What am I part-way through?" — the assistant's resume path.
            $table->index(['user_id', 'completed_at']);

            $table->index('shift_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inspections');
    }
};
