<?php

namespace Tests\Feature\Tickets;

use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The engineer's ticket queue: taking a ticket from the list, and seeing
 * what was finished this week without leaving it.
 *
 * @see resources/views/engineer/tickets/index.blade.php
 */
class TicketQueueTest extends TestCase
{
    use RefreshDatabase;

    private function engineer(): User
    {
        $user = User::factory()->create();
        $user->forceFill(['role' => UserRole::Engineer, 'is_active' => true])->save();

        return $user->fresh();
    }

    public function test_a_ticket_nobody_has_can_be_taken_from_the_queue(): void
    {
        $ticket = Ticket::factory()->create();

        $this->actingAs($this->engineer())
            ->get(route('engineer.tickets.index'))
            ->assertOk()
            ->assertSee(route('tickets.assign', $ticket), escape: false)
            ->assertSee('Take it');
    }

    public function test_taking_it_from_the_queue_makes_it_yours(): void
    {
        $engineer = $this->engineer();
        $ticket = Ticket::factory()->create();

        $this->actingAs($engineer)
            ->from(route('engineer.tickets.index'))
            ->patch(route('tickets.assign', $ticket), ['assigned_to' => $engineer->id])
            ->assertRedirect(route('engineer.tickets.index'));

        $this->assertSame($engineer->id, $ticket->fresh()->assigned_to);
    }

    public function test_a_ticket_somebody_has_is_not_offered(): void
    {
        $ticket = Ticket::factory()->assignedTo($this->engineer())->create();

        $this->actingAs($this->engineer())
            ->get(route('engineer.tickets.index'))
            ->assertOk()
            ->assertSee($ticket->ticket_number)
            ->assertDontSee(route('tickets.assign', $ticket), escape: false);
    }

    public function test_the_open_view_shows_what_was_finished_this_week(): void
    {
        Ticket::factory()->status(TicketStatus::Closed)->create([
            'title' => 'Fixed on Tuesday', 'resolved_at' => now()->subDays(2), 'closed_at' => now()->subDay(),
        ]);
        Ticket::factory()->status(TicketStatus::Closed)->create([
            'title' => 'Fixed last month', 'resolved_at' => now()->subDays(30), 'closed_at' => now()->subDays(29),
        ]);

        $this->actingAs($this->engineer())
            ->get(route('engineer.tickets.index'))
            ->assertOk()
            ->assertSee('Finished this week')
            ->assertSee('Fixed on Tuesday')
            ->assertDontSee('Fixed last month');
    }

    public function test_a_narrowed_view_leaves_the_finished_list_out(): void
    {
        Ticket::factory()->status(TicketStatus::Closed)->create([
            'title' => 'Fixed on Tuesday', 'resolved_at' => now()->subDays(2), 'closed_at' => now()->subDay(),
        ]);

        $this->actingAs($this->engineer())
            ->get(route('engineer.tickets.index', ['mine' => 1]))
            ->assertOk()
            ->assertDontSee('Finished this week');
    }

    public function test_every_row_shows_its_time_against_the_target(): void
    {
        Ticket::factory()->breaching()->create();

        $this->actingAs($this->engineer())
            ->get(route('engineer.tickets.index'))
            ->assertOk()
            ->assertSee('Against its target')
            ->assertSee('d past');
    }
}
