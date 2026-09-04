<?php

namespace Tests\Feature\Tickets;

use App\Enums\AlarmType;
use App\Enums\UserRole;
use App\Models\Alarm;
use App\Models\AuditLog;
use App\Models\Ticket;
use App\Models\TicketComment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * `atlas:renumber-tickets` repairs a database seeded before TicketFactory
 * stopped inventing random references. Numbers like TKT-2026-96851 are
 * unique and harmless, and they stop being the readable sequence the format
 * exists for — and every real ticket raised afterwards continued from there.
 *
 * @see App\Console\Commands\RenumberTickets
 */
class RenumberTicketsCommandTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        $user = User::factory()->create();
        $user->forceFill(['role' => UserRole::Engineer, 'is_active' => true])->save();

        return $user->fresh();
    }

    /** A ticket carrying a specific number, written past the model so the observer is not involved. */
    private function ticket(string $number, string $openedAt): Ticket
    {
        $ticket = Ticket::factory()->create(['opened_at' => $openedAt]);
        DB::table('tickets')->where('id', $ticket->id)->update(['ticket_number' => $number]);

        return $ticket->fresh();
    }

    public function test_a_sequential_database_has_nothing_to_do(): void
    {
        $this->ticket('TKT-2026-00001', '2026-02-01 09:00:00');
        $this->ticket('TKT-2026-00002', '2026-03-01 09:00:00');

        $this->assertSame(0, Artisan::call('atlas:renumber-tickets', ['--force' => true]));
        $this->assertStringContainsString('already numbered in sequence', Artisan::output());
    }

    public function test_a_dry_run_changes_nothing(): void
    {
        $this->ticket('TKT-2026-96851', '2026-02-01 09:00:00');

        $this->assertSame(0, Artisan::call('atlas:renumber-tickets', ['--dry-run' => true]));

        $this->assertStringContainsString('TKT-2026-00001', Artisan::output());
        $this->assertSame('TKT-2026-96851', Ticket::first()->ticket_number);
    }

    public function test_it_renumbers_in_the_order_tickets_were_opened_and_rewrites_references(): void
    {
        $user = $this->user();

        $march = $this->ticket('TKT-2026-96851', '2026-03-01 09:00:00');
        $february = $this->ticket('TKT-2026-00002', '2026-02-01 09:00:00');
        $april = $this->ticket('TKT-2026-43856', '2026-04-01 09:00:00');

        TicketComment::create([
            'ticket_id' => $march->id,
            'user_id' => $user->id,
            'body' => 'Same fault as TKT-2026-96851, see that one first.',
            'is_internal' => true,
        ]);

        Alarm::create([
            'user_id' => $user->id,
            'type' => AlarmType::SlaBreach,
            'title' => 'TKT-2026-43856 is past its target',
            'message' => 'Raised as TKT-2026-43856.',
            'trigger_at' => now(),
        ]);

        $this->assertSame(0, Artisan::call('atlas:renumber-tickets', ['--force' => true]));

        // February was opened first, so it is 00001 whatever number it carried.
        $this->assertSame('TKT-2026-00001', $february->fresh()->ticket_number);
        $this->assertSame('TKT-2026-00002', $march->fresh()->ticket_number);
        $this->assertSame('TKT-2026-00003', $april->fresh()->ticket_number);

        // Text that quoted an old number now quotes the new one.
        $this->assertStringContainsString('TKT-2026-00002', TicketComment::first()->body);
        $this->assertStringNotContainsString('96851', TicketComment::first()->body);
        $this->assertSame('TKT-2026-00003 is past its target', Alarm::first()->title);
        $this->assertSame('Raised as TKT-2026-00003.', Alarm::first()->message);

        // One honest audit row per renumbered ticket, with the old number kept.
        $rows = AuditLog::where('description', 'Renumbered by atlas:renumber-tickets')->get();
        $this->assertCount(3, $rows);
        $this->assertSame(
            ['ticket_number' => 'TKT-2026-96851'],
            $rows->firstWhere('auditable_id', $march->id)->previous,
        );

        // February already had a number that clashed with March's target, which
        // is what the temporary pass exists for. Running again finds nothing.
        Artisan::call('atlas:renumber-tickets', ['--force' => true]);
        $this->assertStringContainsString('already numbered in sequence', Artisan::output());
    }

    public function test_each_year_starts_its_own_sequence(): void
    {
        $this->ticket('TKT-2025-77777', '2025-11-01 09:00:00');
        $this->ticket('TKT-2026-88888', '2026-01-05 09:00:00');

        Artisan::call('atlas:renumber-tickets', ['--force' => true]);

        $this->assertEqualsCanonicalizing(
            ['TKT-2025-00001', 'TKT-2026-00001'],
            Ticket::pluck('ticket_number')->all(),
        );
    }

    public function test_the_confirmation_stops_it_without_force(): void
    {
        $this->ticket('TKT-2026-96851', '2026-02-01 09:00:00');

        $this->artisan('atlas:renumber-tickets')
            ->expectsConfirmation('Renumber these tickets?', 'no')
            ->assertExitCode(0);

        $this->assertSame('TKT-2026-96851', Ticket::first()->ticket_number);
    }
}
