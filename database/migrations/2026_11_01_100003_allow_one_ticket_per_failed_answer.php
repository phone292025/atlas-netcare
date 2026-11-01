<?php

use App\Services\Tickets\TicketService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * One live ticket per failed checklist answer (FR-207), held by the database.
 *
 * TicketService locks the answer's row before it checks, which queues two
 * submits through the service. This index also catches any other writer.
 * A deleted ticket does not count, the same as TicketService::raisedFromCheck(),
 * which reads through the soft-delete scope.
 *
 * If an answer already has two live tickets, the oldest keeps the link and
 * the others lose `inspection_result_id` only. They keep `inspection_id`, so
 * each still points at the inspection it came from, and no ticket is deleted.
 *
 * @see TicketService::ensureNotRaisedYet()
 */
return new class extends Migration
{
    public function up(): void
    {
        $duplicates = DB::table('tickets')
            ->whereNotNull('inspection_result_id')->whereNull('deleted_at')
            ->select('inspection_result_id', DB::raw('min(id) as keep'))
            ->groupBy('inspection_result_id')
            ->havingRaw('count(*) > 1')
            ->get();

        foreach ($duplicates as $duplicate) {
            DB::table('tickets')
                ->where('inspection_result_id', $duplicate->inspection_result_id)
                ->whereNull('deleted_at')
                ->where('id', '>', $duplicate->keep)
                ->update(['inspection_result_id' => null]);
        }

        DB::statement('CREATE UNIQUE INDEX tickets_one_per_failed_answer_unique ON tickets (inspection_result_id) WHERE inspection_result_id IS NOT NULL AND deleted_at IS NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX tickets_one_per_failed_answer_unique');
    }
};
