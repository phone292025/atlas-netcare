<?php

use App\Services\Maintenance\InspectionService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * One unfinished inspection per machine and person, held by the database.
 *
 * InspectionService::start() reads for an unfinished one before it inserts,
 * and two taps on bad Wi-Fi can both miss that read. A partial unique index
 * (only rows with no `completed_at`) makes the second insert fail, and
 * start() hands that tap the first one's record. Finished inspections are
 * history and may repeat as often as a machine is checked.
 *
 * Duplicates already in the table go first, keeping the newest. An
 * unfinished inspection has no answers (they are written on submit, in the
 * same transaction that finishes it), so nothing recorded is lost.
 *
 * Raw SQL because the schema builder has no partial index. The statement is
 * the same on PostgreSQL and SQLite.
 *
 * @see InspectionService::start()
 */
return new class extends Migration
{
    public function up(): void
    {
        $duplicates = DB::table('inspections')
            ->whereNull('completed_at')
            ->select('lab_pc_id', 'user_id', DB::raw('max(id) as keep'))
            ->groupBy('lab_pc_id', 'user_id')
            ->havingRaw('count(*) > 1')
            ->get();

        foreach ($duplicates as $duplicate) {
            DB::table('inspections')
                ->whereNull('completed_at')
                ->where('lab_pc_id', $duplicate->lab_pc_id)
                ->where('user_id', $duplicate->user_id)
                ->where('id', '<', $duplicate->keep)
                ->whereNotExists(fn ($q) => $q->from('inspection_results')->whereColumn('inspection_results.inspection_id', 'inspections.id'))
                ->delete();
        }

        DB::statement('CREATE UNIQUE INDEX inspections_one_unfinished_unique ON inspections (lab_pc_id, user_id) WHERE completed_at IS NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX inspections_one_unfinished_unique');
    }
};
