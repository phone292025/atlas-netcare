<?php

namespace Tests\Feature;

use App\Enums\TicketStatus;
use App\Models\Ticket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Schema traps that only exist on the database we actually ship on.
 *
 * The suite runs on SQLite and the system runs on MariaDB. That gap has now
 * produced three defects — MySQL-only `FIELD()`, MySQL-only `DATE_FORMAT`, and
 * the implicit `ON UPDATE CURRENT_TIMESTAMP` this file exists for — every one
 * of which passed a fully green suite.
 *
 * @see docs/progress/decision-log.md — 2026-08-29
 */
class SchemaIntegrityTest extends TestCase
{
    use RefreshDatabase;

    /**
     * No domain timestamp may carry MariaDB's implicit auto-update.
     *
     * MySQL and MariaDB give the FIRST `timestamp` column in a table an
     * implicit `DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` unless
     * `explicit_defaults_for_timestamp` is on. Three columns recording when
     * something HAPPENED were being reset by any UPDATE that did not name
     * them — `tickets.opened_at` was destroying the SLA baseline on every
     * status transition.
     *
     * `created_at` and `updated_at` are exempt: being touched automatically is
     * their entire job.
     *
     * This can only run on the real database, and the suite runs on SQLite —
     * so in practice it skips. The check that actually runs against MariaDB is
     * the read-only command, which is safe to point at live data:
     *
     *     php artisan atlas:check-schema
     *
     * This test exists so the rule is also enforced automatically the day
     * anybody configures a MySQL test connection.
     */
    public function test_no_domain_timestamp_auto_updates_itself(): void
    {
        $driver = DB::connection()->getDriverName();

        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped("Only meaningful on MySQL/MariaDB; running on {$driver}.");
        }

        $offenders = collect(DB::select(
            "SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = ? AND EXTRA LIKE '%on update%'
             AND COLUMN_NAME NOT IN ('created_at', 'updated_at')",
            [DB::connection()->getDatabaseName()],
        ))->map(fn ($row) => $row->TABLE_NAME.'.'.$row->COLUMN_NAME);

        $this->assertTrue(
            $offenders->isEmpty(),
            'These columns will be silently rewritten on any UPDATE that does not name them: '
            .$offenders->implode(', ')
            .'. Declare them as dateTime rather than timestamp.',
        );
    }

    /**
     * Moving a ticket along must not rewrite when it was opened.
     *
     * The behaviour the schema rule protects. This passes trivially on SQLite,
     * which is exactly why the schema test above exists — but it states the
     * intent where somebody reading TicketService will find it, and it fails
     * loudly on MariaDB if the column type is ever reverted.
     */
    public function test_a_transition_does_not_move_the_sla_baseline(): void
    {
        $ticket = Ticket::factory()->create(['opened_at' => now()->subHours(6)]);
        $openedAt = DB::table('tickets')->where('id', $ticket->id)->value('opened_at');

        DB::table('tickets')->where('id', $ticket->id)
            ->update(['status' => TicketStatus::Acknowledged->value]);

        $this->assertSame(
            $openedAt,
            DB::table('tickets')->where('id', $ticket->id)->value('opened_at'),
            'opened_at moved during a status update — due_at is measured from it, so the SLA is now wrong.',
        );
    }
}
