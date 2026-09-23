<?php

namespace Tests\Feature\Tickets;

use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Tickets\TicketService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * FR-307's "assign": handing a ticket to an engineer, or back to the queue.
 *
 * Assignment is deliberately not a transition. Who is doing the work and how
 * far along it is are different questions.
 *
 * @see App\Services\Tickets\TicketService::assign()
 */
class TicketAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private function user(UserRole $role, bool $active = true): User
    {
        $user = User::factory()->create();
        $user->forceFill(['role' => $role, 'is_active' => $active])->save();

        return $user;
    }

    private function ticket(array $attributes = []): Ticket
    {
        return Ticket::factory()->create([
            'reported_by' => $this->user(UserRole::Assistant)->id,
            ...$attributes,
        ]);
    }

    public function test_an_engineer_can_hand_a_ticket_to_a_colleague(): void
    {
        $first = $this->user(UserRole::Engineer);
        $second = $this->user(UserRole::Engineer);
        $ticket = $this->ticket(['assigned_to' => $first->id, 'status' => TicketStatus::InProgress]);

        $this->actingAs($first)
            ->patch(route('tickets.assign', $ticket), ['assigned_to' => $second->id])
            ->assertRedirect()
            ->assertSessionHas('success', "{$ticket->ticket_number} is now with {$second->name}.");

        $this->assertSame($second->id, $ticket->fresh()->assigned_to);
    }

    public function test_assignment_does_not_move_the_ticket_through_its_lifecycle(): void
    {
        // Reassigning mid-repair must not rewind the state machine.
        $ticket = $this->ticket(['status' => TicketStatus::InProgress]);

        app(TicketService::class)->assign($ticket, $this->user(UserRole::Engineer), $this->user(UserRole::Engineer));

        $this->assertSame(TicketStatus::InProgress, $ticket->fresh()->status);
    }

    public function test_a_ticket_can_be_put_back_in_the_queue(): void
    {
        // How an engineer going on leave returns work without picking a
        // colleague for it.
        $engineer = $this->user(UserRole::Engineer);
        $ticket = $this->ticket(['assigned_to' => $engineer->id]);

        $this->actingAs($engineer)
            ->patch(route('tickets.assign', $ticket), ['assigned_to' => null])
            ->assertSessionHas('success', "{$ticket->ticket_number} is back in the queue.");

        $this->assertNull($ticket->fresh()->assigned_to);
    }

    public function test_work_cannot_be_given_to_a_deactivated_account(): void
    {
        $this->expectException(DomainException::class);

        app(TicketService::class)->assign(
            $this->ticket(),
            $this->user(UserRole::Engineer, active: false),
            $this->user(UserRole::Engineer),
        );
    }

    public function test_work_cannot_be_given_to_an_assistant(): void
    {
        $this->expectException(DomainException::class);

        app(TicketService::class)->assign($this->ticket(), $this->user(UserRole::Assistant), $this->user(UserRole::Engineer));
    }

    public function test_a_closed_ticket_cannot_be_reassigned(): void
    {
        // Closed is terminal, and ownership of finished work is part of its
        // record.
        $this->expectException(DomainException::class);

        app(TicketService::class)->assign(
            $this->ticket(['status' => TicketStatus::Closed]),
            $this->user(UserRole::Engineer),
            $this->user(UserRole::Engineer),
        );
    }

    public function test_an_assistant_cannot_assign_tickets(): void
    {
        $this->actingAs($this->user(UserRole::Assistant))
            ->patch(route('tickets.assign', $this->ticket()), ['assigned_to' => $this->user(UserRole::Engineer)->id])
            ->assertForbidden();
    }

    public function test_assignment_leaves_an_internal_note(): void
    {
        // The trail belongs on the ticket, and internal, because the reporter
        // does not need to watch engineers reshuffling work.
        $engineer = $this->user(UserRole::Engineer);
        $ticket = $this->ticket();

        app(TicketService::class)->assign($ticket, $engineer, $engineer);

        $comment = $ticket->comments()->latest('id')->first();

        $this->assertTrue((bool) $comment->is_internal);
        $this->assertStringContainsString($engineer->name, $comment->body);
    }
}
