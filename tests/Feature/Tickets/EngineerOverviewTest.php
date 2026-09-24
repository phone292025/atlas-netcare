<?php

namespace Tests\Feature\Tickets;

use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The engineer's dashboard: their own work, what nobody has picked up, and
 * the network, on one screen.
 *
 * @see App\Http\Controllers\Engineer\DashboardController::overview()
 */
class EngineerOverviewTest extends TestCase
{
    use RefreshDatabase;

    private function userWith(UserRole $role): User
    {
        $user = User::factory()->create();
        $user->forceFill(['role' => $role])->save();

        return $user;
    }

    public function test_it_lists_my_work_and_what_is_waiting_but_not_other_peoples(): void
    {
        $me = $this->userWith(UserRole::Engineer);
        $colleague = $this->userWith(UserRole::Engineer);

        Ticket::factory()->create(['title' => 'Mine to fix', 'assigned_to' => $me->id, 'status' => TicketStatus::InProgress]);
        Ticket::factory()->create(['title' => 'Nobody has this', 'assigned_to' => null, 'status' => TicketStatus::Open]);
        Ticket::factory()->create(['title' => 'A colleague has this', 'assigned_to' => $colleague->id, 'status' => TicketStatus::Open]);
        Ticket::factory()->create(['title' => 'Mine and done', 'assigned_to' => $me->id, 'status' => TicketStatus::Closed]);

        $this->actingAs($me)
            ->get(route('engineer.dashboard'))
            ->assertOk()
            ->assertSee('Mine to fix')
            ->assertSee('Nobody has this')
            ->assertDontSee('A colleague has this')
            ->assertDontSee('Mine and done');
    }

    public function test_my_queue_puts_the_most_urgent_first(): void
    {
        $me = $this->userWith(UserRole::Engineer);

        Ticket::factory()->create(['title' => 'A low one', 'assigned_to' => $me->id, 'priority' => TicketPriority::Low, 'status' => TicketStatus::Open]);
        Ticket::factory()->create(['title' => 'A critical one', 'assigned_to' => $me->id, 'priority' => TicketPriority::Critical, 'status' => TicketStatus::Open]);

        $this->actingAs($me)
            ->get(route('engineer.dashboard'))
            ->assertSeeInOrder(['A critical one', 'A low one']);
    }

    public function test_take_it_assigns_the_ticket_to_whoever_pressed_it(): void
    {
        $me = $this->userWith(UserRole::Engineer);
        $ticket = Ticket::factory()->create(['assigned_to' => null, 'status' => TicketStatus::Open]);

        $page = $this->actingAs($me)->get(route('engineer.dashboard'))->getContent();
        $this->assertStringContainsString('action="'.route('tickets.assign', $ticket).'"', $page);

        $this->actingAs($me)
            ->from(route('engineer.dashboard'))
            ->patch(route('tickets.assign', $ticket), ['assigned_to' => $me->id])
            ->assertRedirect(route('engineer.dashboard'));

        $this->assertSame($me->id, $ticket->fresh()->assigned_to);
    }

    public function test_the_access_point_list_is_its_own_screen(): void
    {
        // Dashboard and Access points were the same page under two names.
        $engineer = $this->userWith(UserRole::Engineer);

        $this->actingAs($engineer)->get(route('engineer.dashboard'))->assertSee('Your queue');
        $this->actingAs($engineer)->get(route('engineer.aps.index'))->assertDontSee('Your queue');
    }
}
