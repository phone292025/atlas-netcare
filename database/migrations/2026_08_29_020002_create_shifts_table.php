<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One assistant, on one date, in one place.
 *
 * Generated from a template, then editable — an administrator swaps who is on
 * without touching the pattern. `shift_template_id` is nullable so a one-off
 * shift that belongs to no pattern is a first-class thing rather than a
 * template nobody wanted.
 *
 * `start_time` and `end_time` are COPIED from the template rather than read
 * through it. Changing "Mondays 09:00" to 10:00 must not silently rewrite what
 * somebody already worked last week.
 *
 * @see docs/04-database.md
 * @see docs/modules/M6-shifts-and-alarms.md
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shifts', function (Blueprint $table): void {
            $table->id();

            // nullOnDelete: deleting a pattern must not delete the history of
            // shifts people actually worked.
            $table->foreignId('shift_template_id')->nullable()->constrained()->nullOnDelete();

            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('location_id')->constrained()->restrictOnDelete();

            $table->date('shift_date');
            $table->time('start_time');
            $table->time('end_time');

            $table->string('status', 20)->default('scheduled');

            // dateTime, not timestamp: MariaDB auto-updates the first
            // `timestamp` column in a table on any UPDATE that does not name
            // it, which would rewrite a check-in every time the status moved.
            // See docs/progress/decision-log.md — 2026-08-29.
            $table->dateTime('checked_in_at')->nullable();
            $table->dateTime('checked_out_at')->nullable();

            $table->text('handover_notes')->nullable();

            $table->timestamps();

            // "My shift today" — the assistant's home screen.
            $table->index(['user_id', 'shift_date']);

            // The roster week view, and the generator's duplicate check.
            $table->index(['shift_date', 'location_id']);

            /*
             * One person cannot be in two places at once. The generator is
             * scheduled and re-runnable, so without this a second pass over
             * the same week rosters everybody twice.
             */
            $table->unique(['user_id', 'shift_date', 'start_time'], 'shifts_person_slot_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shifts');
    }
};
