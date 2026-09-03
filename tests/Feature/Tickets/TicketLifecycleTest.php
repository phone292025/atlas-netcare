<?php

namespace Tests\Feature\Tickets;

use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Models\LabPc;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Tickets\TicketService;
use DomainException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * M4 — the ticket state machine and its SLA.
 *
 * The lifecycle is Q3's, adopted 2026-08-28. These tests are what make it real:
 * the machine has to refuse illegal moves at the SERVICE, because hiding a
 * button in Blade is a suggestion and a hand-written POST ignores it.
 *
 * @see App\Services\Tickets\TicketService
 * @see docs/02-open-questions.md Q3
 */
class TicketLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private function userWith(UserRole $role): User
    {
        $user = User::factory()->create();
        $user->forceFill(['role' => $role, 'is_active' => true])->save();

        return $user->fresh();
    }

    private function service(): TicketService
    {
        return app(TicketService::class);
    }

    // ------------------------------------------------------------ opening

    public function test_opening_a_ticket_stamps_it_and_sets_a_due_time(): void
    {
        $reporter = $this->userWith(UserRole::Assistant);
        $labPc = LabPc::factory()->create();

        $ticket = $this->service()->open([
            'title' => 'Machine will not power on',
            'description' => 'No fan, no lights.',
            'category' => 'hardware',
            'priority' => TicketPriority::Critical->value,
        ], $reporter, $labPc);

        $this->assertSame(TicketStatus::Open, $ticket->status);
        $this->assertNotNull($ticket->opened_at);
        $this->assertSame($reporter->id, $ticket->reported_by);
        $this->assertSame($labPc->id, $ticket->ticketable_id);

        // Critical is 4 hours in config/atlas.php.
        $this->assertSame(4, (int) round($ticket->opened_at->diffInHours($ticket->due_at)));
    }

    #[DataProvider('slaExpectations')]
    public function test_the_due_time_comes_from_the_priority(string $priority, int $hours): void
    {
        $ticket = $this->service()->open([
            'title' => 'A fault',
            'description' => 'Something is wrong.',
            'category' => 'hardware',
            'priority' => $priority,
        ], $this->userWith(UserRole::Assistant));

        $this->assertSame($hours, (int) round($ticket->opened_at->diffInHours($ticket->due_at)));
    }

    /** @return array<string, array{string, int}> */
    public static function slaExpectations(): array
    {
        // The values live in config/atlas.php, so ratifying different SLA
        // hours is a config edit and this test moves with it.
        return [
            'critical' => ['critical', 4],
            'medium' => ['medium', 24],
            'low' => ['low', 72],
        ];
    }

    public function test_ticket_numbers_are_sequential_and_readable(): void
    {
        $reporter = $this->userWith(UserRole::Assistant);

        $first = $this->service()->open($this->attributes(), $reporter);
        $second = $this->service()->open($this->attributes(), $reporter);

        $year = now()->year;

        $this->assertSame("TKT-{$year}-00001", $first->ticket_number);
        $this->assertSame("TKT-{$year}-00002", $second->ticket_number);
    }

    public function test_a_ticket_can_belong_to_a_place_rather_than_a_machine(): void
    {
        // "No network anywhere on Level 6" is a real ticket.
        $ticket = $this->service()->open($this->attributes(), $this->userWith(UserRole::Assistant));

        $this->assertNull($ticket->ticketable_id);
        $this->assertSame('Unassigned', $ticket->subjectLabel());
    }

    // ------------------------------------------------------ the state machine

    #[DataProvider('legalMoves')]
    public function test_a_legal_transition_is_allowed(string $from, string $to): void
    {
        $this->assertTrue(
            $this->service()->canTransition(TicketStatus::from($from), TicketStatus::from($to))
        );
    }

    /** @return array<string, array{string, string}> */
    public static function legalMoves(): array
    {
        return [
            'open to acknowledged' => ['open', 'acknowledged'],
            'acknowledged to in progress' => ['acknowledged', 'in_progress'],
            'in progress to resolved' => ['in_progress', 'resolved'],
            'resolved to closed' => ['resolved', 'closed'],
            'resolved to reopened' => ['resolved', 'reopened'],
            'reopened to in progress' => ['reopened', 'in_progress'],
        ];
    }

    #[DataProvider('illegalMoves')]
    public function test_an_illegal_transition_is_refused(string $from, string $to): void
    {
        $this->assertFalse(
            $this->service()->canTransition(TicketStatus::from($from), TicketStatus::from($to))
        );
    }

    /** @return array<string, array{string, string}> */
    public static function illegalMoves(): array
    {
        return [
            // The one that matters most: a ticket nobody worked on cannot be
            // declared fixed.
            'open straight to resolved' => ['open', 'resolved'],
            'open straight to closed' => ['open', 'closed'],
            'acknowledged straight to resolved' => ['acknowledged', 'resolved'],
            'closed is final' => ['closed', 'in_progress'],
            'closed cannot reopen directly' => ['closed', 'reopened'],
            'no going backwards' => ['in_progress', 'open'],
            'resolved cannot go back to in progress' => ['resolved', 'in_progress'],
        ];
    }

    public function test_the_service_throws_rather_than_silently_skipping(): void
    {
        $ticket = Ticket::factory()->create(['status' => TicketStatus::Open->value]);
        $engineer = $this->userWith(UserRole::Engineer);

        $this->expectException(DomainException::class);

        $this->service()->transition($ticket, TicketStatus::Closed, $engineer);
    }

    public function test_a_refused_transition_leaves_the_ticket_untouched(): void
    {
        $ticket = Ticket::factory()->create(['status' => TicketStatus::Open->value]);
        $engineer = $this->userWith(UserRole::Engineer);

        try {
            $this->service()->transition($ticket, TicketStatus::Resolved, $engineer, [
                'resolution_notes' => 'Pretended to fix it.',
            ]);
        } catch (DomainException) {
            // expected
        }

        $fresh = $ticket->fresh();

        $this->assertSame(TicketStatus::Open, $fresh->status);
        $this->assertNull($fresh->resolved_at);
        $this->assertNull($fresh->resolution_notes);
    }

    // --------------------------------------------------- what a move records

    public function test_acknowledging_assigns_the_engineer_who_did_it(): void
    {
        // Otherwise the queue fills with acknowledged tickets that nobody owns.
        $ticket = Ticket::factory()->create(['status' => TicketStatus::Open->value]);
        $engineer = $this->userWith(UserRole::Engineer);

        $this->service()->transition($ticket, TicketStatus::Acknowledged, $engineer);

        $fresh = $ticket->fresh();

        $this->assertSame($engineer->id, $fresh->assigned_to);
        $this->assertNotNull($fresh->acknowledged_at);
    }

    public function test_resolving_records_the_notes_and_the_time(): void
    {
        $engineer = $this->userWith(UserRole::Engineer);
        $ticket = Ticket::factory()->assignedTo($engineer)->create();

        $this->service()->transition($ticket, TicketStatus::InProgress, $engineer);
        $this->service()->transition($ticket->fresh(), TicketStatus::Resolved, $engineer, [
            'resolution_notes' => 'Replaced the PSU.',
        ]);

        $fresh = $ticket->fresh();

        $this->assertSame(TicketStatus::Resolved, $fresh->status);
        $this->assertSame('Replaced the PSU.', $fresh->resolution_notes);
        $this->assertNotNull($fresh->resolved_at);
    }

    public function test_reopening_clears_the_resolution(): void
    {
        /*
         * Leaving resolved_at set would make the ticket count as resolved in
         * every report while sitting in the queue as open work — the figures
         * and the screen would disagree about the same row.
         */
        $engineer = $this->userWith(UserRole::Engineer);
        $reporter = $this->userWith(UserRole::Assistant);

        $ticket = Ticket::factory()->assignedTo($engineer)->create([
            'reported_by' => $reporter->id,
        ]);

        $this->service()->transition($ticket, TicketStatus::InProgress, $engineer);
        $this->service()->transition($ticket->fresh(), TicketStatus::Resolved, $engineer, [
            'resolution_notes' => 'Replaced the PSU.',
        ]);
        $this->service()->transition($ticket->fresh(), TicketStatus::Reopened, $reporter);

        $fresh = $ticket->fresh();

        $this->assertSame(TicketStatus::Reopened, $fresh->status);
        $this->assertNull($fresh->resolved_at);
        $this->assertNull($fresh->resolution_notes);
    }

    public function test_every_move_leaves_a_trail(): void
    {
        $ticket = Ticket::factory()->create(['status' => TicketStatus::Open->value]);
        $engineer = $this->userWith(UserRole::Engineer);

        $this->service()->transition($ticket, TicketStatus::Acknowledged, $engineer);

        $this->assertSame(1, $ticket->comments()->count());
        $this->assertTrue($ticket->comments()->first()->is_internal);
    }

    // ------------------------------------------------------------ SLA

    public function test_a_ticket_past_its_due_time_is_breaching(): void
    {
        $ticket = Ticket::factory()->breaching()->create();

        $this->assertTrue($ticket->isBreaching());
        $this->assertSame(1, Ticket::query()->breaching()->count());
    }

    public function test_a_finished_ticket_is_not_breaching_however_late(): void
    {
        // It took too long, but nobody can act on it now — the alarm sweep
        // wants work that still needs doing, not a history lesson.
        $ticket = Ticket::factory()->breaching()->create();
        $ticket->forceFill([
            'status' => TicketStatus::Closed,
            'closed_at' => now(),
        ])->save();

        $this->assertFalse($ticket->fresh()->isBreaching());
        $this->assertSame(0, Ticket::query()->breaching()->count());
    }

    // ------------------------------------------------- the auto-ticket guard

    public function test_an_asset_with_an_open_ticket_is_reported_as_such(): void
    {
        /*
         * FR-503: an access point offline for six hours must produce one
         * ticket, not one per polling interval. M5's collector asks this
         * before raising another.
         */
        $labPc = LabPc::factory()->create();

        $this->assertFalse($this->service()->hasOutstandingFor($labPc));

        $this->service()->open($this->attributes(), $this->userWith(UserRole::Assistant), $labPc);

        $this->assertTrue($this->service()->hasOutstandingFor($labPc));
    }

    public function test_a_closed_ticket_no_longer_blocks_a_new_one(): void
    {
        $labPc = LabPc::factory()->create();
        $ticket = $this->service()->open($this->attributes(), $this->userWith(UserRole::Assistant), $labPc);

        $ticket->forceFill(['status' => TicketStatus::Closed])->save();

        $this->assertFalse($this->service()->hasOutstandingFor($labPc));
    }

    /** @return array<string, mixed> */
    private function attributes(): array
    {
        return [
            'title' => 'A fault',
            'description' => 'Something is wrong.',
            'category' => 'hardware',
            'priority' => TicketPriority::Medium->value,
        ];
    }

    /*
    |---------------------------------------------------------------------
    | Two people pressing Submit at once
    |---------------------------------------------------------------------
    |
    | `reserveNumber()` reads the highest number and adds one, so two requests
    | that read before either writes compute the same string and the loser hits
    | the unique index. Until 2026-09-04 that became a 500: the assistant had
    | typed a fault description, got an error page, and the fault was still
    | there. Rare on a campus this size, and trivially demonstrable wrong.
    |
    | Real concurrency cannot be reproduced in PHPUnit, so `reserveNumber()` is
    | protected and these subclass it to hand back a number that is already
    | taken. That is exactly what losing the race looks like from inside the
    | method.
    */

    public function test_a_taken_ticket_number_is_retried_rather_than_thrown(): void
    {
        $reporter = $this->userWith(UserRole::Assistant);

        // The winner of the race, holding the number the loser will compute.
        $taken = $this->service()->open($this->attributes(), $reporter);

        $loser = new class extends TicketService
        {
            public int $reads = 0;

            public string $collideWith = '';

            protected function reserveNumber(): string
            {
                $this->reads++;

                // Collide once, then behave — the second read sees what the
                // winner committed, which is what a real retry gets.
                return $this->reads === 1 ? $this->collideWith : parent::reserveNumber();
            }
        };

        $loser->collideWith = $taken->ticket_number;

        $ticket = $loser->open($this->attributes(), $reporter);

        $this->assertSame(2, $loser->reads, 'It should have re-read the number after the collision.');
        $this->assertSame('TKT-'.now()->year.'-00002', $ticket->ticket_number);
        $this->assertSame(2, Ticket::count());
    }

    public function test_it_gives_up_instead_of_looping_forever(): void
    {
        /*
         * The other half, and the more important one: a retry that never stops
         * is worse than the 500 it replaced, because it hangs the request
         * instead of failing it. This subclass never yields a free number.
         */
        $reporter = $this->userWith(UserRole::Assistant);
        $taken = $this->service()->open($this->attributes(), $reporter);

        $doomed = new class extends TicketService
        {
            public string $collideWith = '';

            protected function reserveNumber(): string
            {
                return $this->collideWith;
            }
        };

        $doomed->collideWith = $taken->ticket_number;

        try {
            $doomed->open($this->attributes(), $reporter);
            $this->fail('It should have given up after the retry limit.');
        } catch (UniqueConstraintViolationException) {
            // What we want: it stops, and it stops loudly.
        }

        $this->assertSame(1, Ticket::count(), 'A failed attempt must not leave half a ticket behind.');
    }
}
