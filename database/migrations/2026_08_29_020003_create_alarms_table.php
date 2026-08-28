<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Something one person needs to be told, at a particular time.
 *
 * SCHEDULED, NOT IMMEDIATE. A row with `trigger_at` in the future is the
 * normal case, and a dispatcher sends it when due. That is what makes
 * "remind me to do the 11:00 inspection round" possible at all — an alarm
 * table that only held things that had already happened could not express it.
 *
 * THE DELIVERY MECHANISM FOR THE WHOLE SYSTEM (FR-506). M4 raises SLA
 * breaches here and M5 raises access-point outages, both through
 * AlarmService — neither touches this table directly.
 *
 * @see docs/06-database.md
 * @see docs/04-open-questions.md Q5
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alarms', function (Blueprint $table): void {
            $table->id();

            // Alarms belong to a person. Deactivated accounts keep theirs,
            // same as their tickets and inspections.
            $table->foreignId('user_id')->constrained()->restrictOnDelete();

            $table->foreignId('shift_id')->nullable()->constrained()->cascadeOnDelete();

            $table->string('type', 30);
            $table->string('title');
            $table->text('message')->nullable();

            /*
             * What the alarm is about — a ticket, an access point, an
             * inspection. Polymorphic and nullable, because a shift reminder
             * is about nothing but the shift.
             *
             * Deliberately NOT a foreign key: it points at four different
             * tables, and the alarm must outlive whatever it referred to.
             */
            $table->nullableMorphs('related');

            $table->dateTime('trigger_at');
            $table->dateTime('sent_at')->nullable();
            $table->dateTime('read_at')->nullable();

            $table->timestamps();

            // The unread badge count, on every page load for every user.
            $table->index(['user_id', 'read_at']);

            // The dispatcher sweep: due, and not yet sent.
            $table->index(['sent_at', 'trigger_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alarms');
    }
};
