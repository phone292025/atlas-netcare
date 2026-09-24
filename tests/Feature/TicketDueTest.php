<?php

namespace Tests\Feature;

use App\Enums\TicketStatus;
use App\Models\Ticket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/**
 * <x-ticket-due>: when a ticket is due, in the words a queue is scanned by.
 */
class TicketDueTest extends TestCase
{
    use RefreshDatabase;

    private function render(array $attributes): string
    {
        return Blade::render('<x-ticket-due :ticket="$ticket" />', [
            'ticket' => Ticket::factory()->make($attributes),
        ]);
    }

    public function test_an_open_ticket_past_its_target_says_how_late_it_is(): void
    {
        $html = $this->render(['status' => TicketStatus::Open, 'due_at' => now()->subHours(3)]);

        $this->assertStringContainsString('Overdue by 3 hours', $html);
        $this->assertStringContainsString('text-red-700', $html);
    }

    public function test_an_open_ticket_inside_its_last_hour_is_amber(): void
    {
        $html = $this->render(['status' => TicketStatus::InProgress, 'due_at' => now()->addMinutes(30)->addSeconds(5)]);

        $this->assertStringContainsString('Due in 30 minutes', $html);
        $this->assertStringContainsString('text-amber-700', $html);
    }

    public function test_a_finished_ticket_says_whether_it_made_its_target(): void
    {
        // A dash said nothing, and "was it on time?" is the only question
        // anyone looking back at a closed ticket is asking.
        $due = now()->subDay();

        $onTime = $this->render(['status' => TicketStatus::Resolved, 'due_at' => $due, 'resolved_at' => $due->copy()->subHour()]);
        $late = $this->render(['status' => TicketStatus::Closed, 'due_at' => $due, 'resolved_at' => $due->copy()->addHour()]);

        $this->assertStringContainsString('Made its target', $onTime);
        $this->assertStringContainsString('Finished after its target', $late);
    }

    public function test_a_ticket_with_no_target_says_so(): void
    {
        $this->assertStringContainsString('No target', $this->render(['status' => TicketStatus::Open, 'due_at' => null]));
    }
}
