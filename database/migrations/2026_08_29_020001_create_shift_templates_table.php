<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A recurring weekly slot: "Mondays 09:00-13:00, Level 6 IT Lab, 2 assistants".
 *
 * Q4: templates rather than one-at-a-time assignment. An administrator sets up
 * a pattern once and a scheduled command generates dated shifts from it,
 * instead of entering shifts forever.
 *
 * @see docs/04-database.md
 * @see docs/02-open-questions.md Q4
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shift_templates', function (Blueprint $table): void {
            $table->id();

            $table->string('name');

            // 0 = Sunday, matching Carbon::dayOfWeek. Stated here because the
            // alternative convention (1 = Monday) is equally common and the
            // difference is a silent off-by-one across the whole roster.
            $table->unsignedTinyInteger('day_of_week');

            $table->time('start_time');
            $table->time('end_time');

            $table->foreignId('location_id')->constrained()->restrictOnDelete();

            // How many assistants this slot needs. The generator creates one
            // shift per assistant, so a slot needing two produces two rows.
            $table->unsignedTinyInteger('required_assistants')->default(1);

            $table->boolean('is_active')->default(true);

            $table->timestamps();

            // The generator reads exactly this: active templates for a weekday.
            $table->index(['is_active', 'day_of_week']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shift_templates');
    }
};
