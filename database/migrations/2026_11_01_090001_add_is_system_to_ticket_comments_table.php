<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which ticket comments TicketService wrote itself: a status change or an
 * assignment, shown on the timeline as something that happened.
 *
 * The timeline used to decide that from how the text began, so an engineer
 * could type an internal note reading "Assigned to ..." and it showed as a
 * recorded event. Only the service sets this column, and it is never
 * fillable.
 *
 * Existing rows are marked by the old rule: they were written before anybody
 * knew the trail could be imitated, and losing the true events would be worse.
 * LIKE is case-sensitive on PostgreSQL, as the old Str::startsWith rule was.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ticket_comments', function (Blueprint $table): void {
            $table->boolean('is_system')->default(false)->after('is_internal');
        });

        DB::table('ticket_comments')
            ->where('is_internal', true)
            ->where(fn ($query) => $query
                ->where('body', 'like', 'Moved this ticket from %')
                ->orWhere('body', 'like', 'Assigned to %')
                ->orWhere('body', 'Unassigned and returned to the queue.'))
            ->update(['is_system' => true]);
    }

    public function down(): void
    {
        Schema::table('ticket_comments', function (Blueprint $table): void {
            $table->dropColumn('is_system');
        });
    }
};
