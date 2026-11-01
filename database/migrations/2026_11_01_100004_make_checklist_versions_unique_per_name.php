<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One checklist template per name and version, held by the database.
 *
 * ChecklistService works out the next version from the highest one with the
 * name, under a table lock. The lock does nothing on SQLite, and a new
 * template used to start at version 1 whatever already had its name. A
 * unique key makes a second "v3" fail instead of quietly existing.
 *
 * Duplicates already in the table keep their oldest row's number; the later
 * ones move to the next free numbers for that name, oldest first. A version
 * number is a label for people, and no row's id or items change.
 */
return new class extends Migration
{
    public function up(): void
    {
        $duplicates = DB::table('checklist_templates')
            ->select('name', 'version')
            ->groupBy('name', 'version')
            ->havingRaw('count(*) > 1')
            ->get();

        foreach ($duplicates as $duplicate) {
            $later = DB::table('checklist_templates')
                ->where('name', $duplicate->name)
                ->where('version', $duplicate->version)
                ->orderBy('id')
                ->skip(1)
                ->take(PHP_INT_MAX)
                ->pluck('id');

            foreach ($later as $id) {
                $next = (int) DB::table('checklist_templates')->where('name', $duplicate->name)->max('version') + 1;
                DB::table('checklist_templates')->where('id', $id)->update(['version' => $next]);
            }
        }

        Schema::table('checklist_templates', function (Blueprint $table): void {
            $table->unique(['name', 'version'], 'checklist_templates_name_version_unique');
        });
    }

    public function down(): void
    {
        Schema::table('checklist_templates', function (Blueprint $table): void {
            $table->dropUnique('checklist_templates_name_version_unique');
        });
    }
};
