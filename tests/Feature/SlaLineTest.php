<?php

namespace Tests\Feature;

use App\Enums\TicketStatus;
use App\Models\Ticket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/**
 * <x-sla-line>: a ticket's time against its target, drawn as a line.
 *
 * The line is the target, measured from when the ticket opened, then either
 * what is left of it or how far past it the ticket went. The sentence from
 * <x-ticket-due> rides along for screen readers, which cannot see a line.
 */
class SlaLineTest extends TestCase
{
    use RefreshDatabase;

    private function render(array $attributes): string
    {
        return Blade::render('<x-sla-line :ticket="$ticket" />', [
            'ticket' => Ticket::factory()->make($attributes),
        ]);
    }

    public function test_an_open_ticket_past_its_target_shows_the_target_then_how_far_past(): void
    {
        $html = $this->render(['status' => TicketStatus::Open, 'opened_at' => now()->subHours(20), 'due_at' => now()->subHours(16)]);

        $this->assertStringContainsString('>4 h<', $html);
        $this->assertStringContainsString('16 h past', $html);
        $this->assertStringContainsString('text-red-700', $html);
        $this->assertStringContainsString('Overdue by 16 hours', $html);
    }

    public function test_an_open_ticket_inside_its_target_shows_what_is_left(): void
    {
        $html = $this->render(['status' => TicketStatus::InProgress, 'opened_at' => now()->subHours(6), 'due_at' => now()->addHours(18)]);

        $this->assertStringContainsString('>6 h<', $html);
        $this->assertStringContainsString('18 h left', $html);
        $this->assertStringNotContainsString('past', $html);
    }

    public function test_the_last_hour_before_the_target_is_amber(): void
    {
        $html = $this->render(['status' => TicketStatus::Open, 'opened_at' => now()->subMinutes(210), 'due_at' => now()->addMinutes(30)]);

        $this->assertStringContainsString('30 min left', $html);
        $this->assertStringContainsString('text-amber-700', $html);
    }

    public function test_a_ticket_finished_inside_its_target_shows_the_time_to_spare(): void
    {
        $opened = now()->subDays(2);

        $html = $this->render([
            'status' => TicketStatus::Closed,
            'opened_at' => $opened,
            'due_at' => $opened->copy()->addHours(24),
            'resolved_at' => $opened->copy()->addHours(17),
        ]);

        $this->assertStringContainsString('>17 h<', $html);
        $this->assertStringContainsString('7 h to spare', $html);
        $this->assertStringContainsString('text-emerald-700', $html);
        $this->assertStringContainsString('Made its target', $html);
    }

    public function test_a_ticket_finished_late_shows_how_far_past_it_went(): void
    {
        $opened = now()->subDays(3);

        $html = $this->render([
            'status' => TicketStatus::Closed,
            'opened_at' => $opened,
            'due_at' => $opened->copy()->addHours(24),
            'resolved_at' => $opened->copy()->addHours(28),
        ]);

        $this->assertStringContainsString('>24 h<', $html);
        $this->assertStringContainsString('4 h past', $html);
        $this->assertStringContainsString('Finished after its target', $html);
    }

    public function test_long_spans_read_in_days(): void
    {
        $html = $this->render(['status' => TicketStatus::Open, 'opened_at' => now()->subDays(9), 'due_at' => now()->subDays(6)]);

        $this->assertStringContainsString('>3 d<', $html);
        $this->assertStringContainsString('6 d past', $html);
    }

    public function test_a_ticket_with_no_target_says_so(): void
    {
        $this->assertStringContainsString('No target', $this->render(['status' => TicketStatus::Open, 'due_at' => null]));
    }
}
