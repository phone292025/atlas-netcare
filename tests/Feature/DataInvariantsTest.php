<?php

namespace Tests\Feature;

use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\LabPc;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Damage the schema audit cannot see, and the history that explains it.
 *
 * Both of these came out of one ticket. It carried a `closed_at` eight hours
 * before its own `opened_at` — left behind by the MariaDB timestamp bug fixed
 * on 2026-08-29 — and two things were wrong beyond the row itself: nothing
 * checked for contradictions like that, and tickets were not audited, so there
 * was no history to reconstruct what had happened.
 *
 * The schema check stops the CAUSE. These stop the damage going unnoticed.
 *
 * @see app/Console/Commands/CheckSchema.php
 * @see docs/progress/decision-log.md — 2026-08-30
 */
class DataInvariantsTest extends TestCase
{
    use RefreshDatabase;

    private function user(UserRole $role): User
    {
        $user = User::factory()->create();
        $user->forceFill(['role' => $role, 'is_active' => true])->save();

        return $user;
    }

    public function test_a_clean_database_passes(): void
    {
        Ticket::factory()->create(['reported_by' => $this->user(UserRole::Assistant)->id]);

        $this->artisan('atlas:check-schema')->assertExitCode(0);
    }

    public function test_a_ticket_resolved_before_it_was_opened_is_caught(): void
    {
        // The exact shape of the real damage.
        Ticket::factory()->create([
            'reported_by' => $this->user(UserRole::Assistant)->id,
            'status' => TicketStatus::Resolved,
            'opened_at' => now(),
            'resolved_at' => now()->subHours(8),
        ]);

        $this->artisan('atlas:check-schema')
            ->expectsOutputToContain('A ticket resolved before it was opened')
            ->assertExitCode(1);
    }

    public function test_a_ticket_due_before_it_was_opened_is_caught(): void
    {
        // due_at is written from opened_at at creation, so the two drifting
        // apart means one of them moved afterwards.
        Ticket::factory()->create([
            'reported_by' => $this->user(UserRole::Assistant)->id,
            'opened_at' => now(),
            'due_at' => now()->subDay(),
        ]);

        $this->artisan('atlas:check-schema')->assertExitCode(1);
    }

    public function test_a_ticket_holding_a_resolution_it_has_not_reached_is_caught(): void
    {
        /*
         * The state machine forbids this: `closed` is terminal and
         * `acknowledged` only leads to `in_progress`. A row in this state was
         * written around TicketService rather than through it.
         */
        Ticket::factory()->create([
            'reported_by' => $this->user(UserRole::Assistant)->id,
            'status' => TicketStatus::Acknowledged,
            'closed_at' => now(),
        ]);

        $this->artisan('atlas:check-schema')
            ->expectsOutputToContain('A ticket carrying a resolution it has not reached')
            ->assertExitCode(1);
    }

    public function test_the_check_changes_nothing(): void
    {
        // Documented as read only, so it is safe to point at real data. A test
        // using RefreshDatabase against the live database would wipe it.
        $ticket = Ticket::factory()->create([
            'reported_by' => $this->user(UserRole::Assistant)->id,
            'opened_at' => now(),
            'due_at' => now()->subDay(),
        ]);

        $before = $ticket->fresh()->toArray();

        $this->artisan('atlas:check-schema');

        $this->assertSame($before, $ticket->fresh()->toArray());
    }

    public function test_an_assistant_raising_a_ticket_writes_an_audit_row(): void
    {
        /*
         * Tickets were the one module with a state machine and no history at
         * all. An assistant is who raises most of them, so this is the path
         * that matters most.
         */
        $assistant = $this->user(UserRole::Assistant);
        $pc = LabPc::factory()->create();

        $this->actingAs($assistant)->post(route('assistant.tickets.store'), [
            'title' => 'Machine will not boot',
            'description' => 'Fans spin, no display.',
            'category' => 'hardware',
            'priority' => TicketPriority::Critical->value,
            'ticketable_type' => 'lab_pc',
            'ticketable_id' => $pc->id,
        ])->assertSessionHasNoErrors();

        $ticket = Ticket::latest('id')->firstOrFail();

        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => Ticket::class,
            'auditable_id' => $ticket->id,
            'action' => 'created',
            'user_id' => $assistant->id,
        ]);
    }

    public function test_a_transition_records_who_moved_it(): void
    {
        // "Who closed this and when" is the question that could not be
        // answered about the damaged ticket.
        $assistant = $this->user(UserRole::Assistant);
        $engineer = $this->user(UserRole::Engineer);

        $ticket = Ticket::factory()->create([
            'reported_by' => $assistant->id,
            'status' => TicketStatus::Open,
        ]);

        $this->actingAs($engineer)
            ->patch(route('tickets.transition', $ticket), ['status' => TicketStatus::Acknowledged->value])
            ->assertSessionHasNoErrors();

        $log = AuditLog::where('auditable_type', Ticket::class)
            ->where('auditable_id', $ticket->id)
            ->where('action', 'updated')
            ->latest('id')
            ->first();

        $this->assertNotNull($log, 'A transition must leave a trail.');
        $this->assertSame($engineer->id, $log->user_id);
    }

    public function test_an_assistant_cannot_read_the_audit_log(): void
    {
        // Auditing tickets adds rows describing other people's work. The log
        // screen stays admin-only.
        $this->actingAs($this->user(UserRole::Assistant))
            ->get(route('admin.audit.index'))
            ->assertForbidden();
    }

    public function test_an_assistant_still_sees_only_their_own_tickets(): void
    {
        // Auditing must not change visibility. An assistant sees their own
        // tickets and nobody else's.
        $mine = $this->user(UserRole::Assistant);
        $theirs = $this->user(UserRole::Assistant);

        Ticket::factory()->create(['reported_by' => $mine->id, 'title' => 'My own fault report']);
        Ticket::factory()->create(['reported_by' => $theirs->id, 'title' => 'Somebody elses report']);

        $this->actingAs($mine)
            ->get(route('assistant.tickets.index'))
            ->assertOk()
            ->assertSee('My own fault report')
            ->assertDontSee('Somebody elses report');
    }
}
