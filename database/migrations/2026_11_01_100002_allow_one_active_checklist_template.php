<?php

use App\Services\Maintenance\ChecklistService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * At most one active checklist template, held by the database.
 *
 * ChecklistService::activate() turns the others off before turning one on,
 * and ChecklistTemplate::current() reads the active one. Two active rows made
 * current() depend on version order, and two assistants could answer
 * different checklists in the same room. A partial unique index over the
 * active rows makes a second one fail to save.
 *
 * If two are active already, the one current() returns (the highest version)
 * stays active and the rest are turned off. `WHERE is_active` reads the same
 * on PostgreSQL's boolean and SQLite's 0 and 1.
 *
 * @see ChecklistService::activate()
 */
return new class extends Migration
{
    public function up(): void
    {
        $current = DB::table('checklist_templates')->where('is_active', true)
            ->orderByDesc('version')->orderByDesc('id')->value('id');

        if ($current !== null) {
            DB::table('checklist_templates')->where('is_active', true)->where('id', '!=', $current)
                ->update(['is_active' => false]);
        }

        DB::statement('CREATE UNIQUE INDEX checklist_templates_one_active_unique ON checklist_templates (is_active) WHERE is_active');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX checklist_templates_one_active_unique');
    }
};
