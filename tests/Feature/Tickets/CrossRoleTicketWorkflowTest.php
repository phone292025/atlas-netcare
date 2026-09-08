<?php

namespace Tests\Feature\Tickets;

use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Models\LabPc;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * One fault, from the person who found it to the person who fixed it.
 *
 * WHY A WHOLE-WORKFLOW TEST EXISTS ALONGSIDE THE UNIT ONES. Every step below
 * is already tested on its own: TicketLifecycleTest pins the state machine,
 * TicketAccessTest pins who may reach which screen. What neither can show is
 * that the steps CONNECT — that what the assistant posts is what the engineer
 * finds in their queue, that the status the engineer sets is the status the
 * assistant then sees on their own ticket, and that the handover carries the
 * assignment with it.
 *
 * Those joins are where a change to one module quietly breaks another, and
 * they are invisible to a test that only ever holds one role at a time.
 *
 * The route names read oddly on purpose: `assistant.tickets.*` is fixed
 * vocabulary rather than a claim about who may use it, and the transition and
 * assign routes sit outside both role groups because TicketPolicy decides, not
 * the URL prefix. See routes/web.php.
 *
 * @see docs/modules/M4-ticketing.md
 * @see docs/01-requirements.md FR-206, FR-208, FR-302, FR-306, FR-307
 */
class CrossRoleTicketWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function userWith(UserRole $role, string $name): User
    {
        $user = User::factory()->create(['name' => $name]);
        $user->forceFill(['role' => $role, 'is_active' => true])->save();

        return $user->fresh();
    }

    public function test_a_fault_travels_from_the_assistant_who_found_it_to_the_engineer_who_fixed_it(): void
    {
        $assistant = $this->userWith(UserRole::Assistant, 'Reporting Assistant');
        $engineer = $this->userWith(UserRole::Engineer, 'Fixing Engineer');
        $labPc = LabPc::factory()->create();

        // ---------------------------------------- 1. the assistant raises it

        $this->actingAs($assistant)
            ->post(route('assistant.tickets.store'), [
                'title' => 'Machine will not power on',
                'description' => 'No fan and no lights when the button is pressed.',
                'category' => 'hardware',
                'priority' => TicketPriority::Critical->value,
                'ticketable_type' => 'lab_pc',
                'ticketable_id' => $labPc->id,
            ])
            ->assertRedirect();

        $ticket = Ticket::query()->latest('id')->firstOrFail();

        $this->assertSame($assistant->id, $ticket->reported_by);
        $this->assertSame(TicketStatus::Open, $ticket->status);
        $this->assertNotNull($ticket->opened_at, 'The SLA baseline was never stamped.');
        $this->assertNotNull($ticket->due_at, 'A Critical ticket came out with no target.');

        // ---------------------------------------- 2. it reaches the queue

        $this->actingAs($engineer)
            ->get(route('engineer.tickets.index'))
            ->assertOk()
            ->assertSee($ticket->ticket_number)
            ->assertSee('Machine will not power on');

        // ---------------------------------------- 3. the assistant may not work it

        $this->actingAs($assistant)
            ->patch(route('tickets.transition', $ticket), [
                'status' => TicketStatus::Acknowledged->value,
            ])
            ->assertForbidden();

        $this->assertSame(
            TicketStatus::Open,
            $ticket->fresh()->status,
            'A refused transition still moved the ticket.',
        );

        // ---------------------------------------- 4. the engineer picks it up

        $this->actingAs($engineer)
            ->patch(route('tickets.transition', $ticket), [
                'status' => TicketStatus::Acknowledged->value,
            ])
            ->assertRedirect();

        $ticket->refresh();

        $this->assertSame(TicketStatus::Acknowledged, $ticket->status);
        $this->assertSame(
            $engineer->id,
            $ticket->assigned_to,
            'Acknowledging did not put the engineer who did it on the ticket.',
        );
        $this->assertNotNull($ticket->acknowledged_at);

        // ---------------------------------------- 5. the reporter sees the move

        $this->actingAs($assistant)
            ->get(route('assistant.tickets.show', $ticket))
            ->assertOk()
            ->assertSee('Acknowledged');

        // ---------------------------------------- 6. resolving needs an account of it

        $this->actingAs($engineer)
            ->patch(route('tickets.transition', $ticket), [
                'status' => TicketStatus::Resolved->value,
            ])
            ->assertSessionHasErrors('resolution_notes');

        $this->assertSame(
            TicketStatus::Acknowledged,
            $ticket->fresh()->status,
            'A ticket resolved with no account of what was done.',
        );

        // ---------------------------------------- 7. and then resolves

        $this->actingAs($engineer)
            ->patch(route('tickets.transition', $ticket), [
                'status' => TicketStatus::InProgress->value,
            ])->assertRedirect();

        $this->actingAs($engineer)
            ->patch(route('tickets.transition', $ticket), [
                'status' => TicketStatus::Resolved->value,
                'resolution_notes' => 'Replaced the power supply unit.',
            ])->assertRedirect();

        $ticket->refresh();

        $this->assertSame(TicketStatus::Resolved, $ticket->status);
        $this->assertSame('Replaced the power supply unit.', $ticket->resolution_notes);
        $this->assertNotNull($ticket->resolved_at);

        // ---------------------------------------- 8. the reporter is told

        $this->actingAs($assistant)
            ->get(route('assistant.tickets.show', $ticket))
            ->assertOk()
            ->assertSee('Resolved')
            ->assertSee('Replaced the power supply unit.');

        // ---------------------------------------- 9. and it is all on the record

        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => (new Ticket)->getMorphClass(),
            'auditable_id' => $ticket->id,
            'user_id' => $engineer->id,
        ]);
    }

    public function test_an_engineer_can_hand_a_ticket_to_a_colleague(): void
    {
        // FR-307. A ticket picked up by the wrong person, or by somebody who
        // then went on leave, could otherwise only be moved in the database.
        $assistant = $this->userWith(UserRole::Assistant, 'Reporter');
        $first = $this->userWith(UserRole::Engineer, 'First Engineer');
        $second = $this->userWith(UserRole::Engineer, 'Second Engineer');

        $ticket = Ticket::factory()->create([
            'reported_by' => $assistant->id,
            'status' => TicketStatus::Acknowledged,
            'assigned_to' => $first->id,
        ]);

        $this->actingAs($first)
            ->patch(route('tickets.assign', $ticket), ['assigned_to' => $second->id])
            ->assertRedirect();

        $this->assertSame($second->id, $ticket->fresh()->assigned_to);

        // And the assistant who reported it cannot reassign it to themselves.
        $this->actingAs($assistant)
            ->patch(route('tickets.assign', $ticket), ['assigned_to' => $assistant->id])
            ->assertForbidden();

        $this->assertSame($second->id, $ticket->fresh()->assigned_to);
    }

    public function test_a_ticket_cannot_be_handed_to_somebody_who_cannot_work_it(): void
    {
        $engineer = $this->userWith(UserRole::Engineer, 'On Duty');
        $assistant = $this->userWith(UserRole::Assistant, 'Not An Engineer');

        $ticket = Ticket::factory()->create([
            'status' => TicketStatus::Acknowledged,
            'assigned_to' => $engineer->id,
        ]);

        /*
         * The refusal is a DomainException from TicketService, flashed as
         * `error` rather than raised as a validation failure. That split is
         * deliberate and documented on AssignTicketRequest: the request
         * validates the SHAPE of the input, and whether that person may hold
         * work is a business rule the service owns.
         */
        $this->actingAs($engineer)
            ->patch(route('tickets.assign', $ticket), ['assigned_to' => $assistant->id])
            ->assertSessionHas('error', 'Only an engineer can be assigned a ticket.');

        $this->assertSame(
            $engineer->id,
            $ticket->fresh()->assigned_to,
            'A ticket was assigned to somebody with no queue to work it from.',
        );
    }

    public function test_a_ticket_cannot_be_handed_to_a_deactivated_account(): void
    {
        $engineer = $this->userWith(UserRole::Engineer, 'On Duty');
        $gone = $this->userWith(UserRole::Engineer, 'Left The Team');
        $gone->forceFill(['is_active' => false])->save();

        $ticket = Ticket::factory()->create([
            'status' => TicketStatus::Acknowledged,
            'assigned_to' => $engineer->id,
        ]);

        $this->actingAs($engineer)
            ->patch(route('tickets.assign', $ticket), ['assigned_to' => $gone->id])
            ->assertSessionHas('error');

        $this->assertSame($engineer->id, $ticket->fresh()->assigned_to);
    }
}
