<?php

namespace Tests\Feature\Tickets;

use App\Enums\DeviceStatus;
use App\Enums\TicketCategory;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Models\LabPc;
use App\Models\Location;
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

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function missingResolutionNotes(): array
    {
        return [
            'no notes at all' => [[]],
            'an empty string' => [['resolution_notes' => '']],
            'only whitespace' => [['resolution_notes' => "   \n "]],
        ];
    }

    /** @param  array<string, mixed>  $extra */
    #[DataProvider('missingResolutionNotes')]
    public function test_the_service_itself_refuses_to_resolve_without_notes(array $extra): void
    {
        /*
         * "No silent closures" lived only in TransitionTicketRequest, so the
         * screen enforced it and the service did not. Called directly, it
         * resolved the ticket and stored null. Found by driving the service
         * against the live database; the HTTP test could not see it because
         * the form stopped the request first.
         */
        $ticket = Ticket::factory()->create(['status' => TicketStatus::InProgress->value]);
        $engineer = $this->userWith(UserRole::Engineer);

        try {
            $this->service()->transition($ticket, TicketStatus::Resolved, $engineer, $extra);
            $this->fail('A ticket was resolved with nobody saying what was done.');
        } catch (DomainException $e) {
            $this->assertSame('Say what you did to fix it before resolving.', $e->getMessage());
        }

        $this->assertSame(TicketStatus::InProgress, $ticket->fresh()->status);
        $this->assertNull($ticket->fresh()->resolved_at);
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

    /*
    |---------------------------------------------------------------------
    | One format, one counter
    |---------------------------------------------------------------------
    |
    | The reference format was written twice: in TicketService and again in
    | TicketFactory, where it invented a RANDOM number between 1 and 99999.
    | Seeding pushed the highest to around 96,000, and reserveNumber() takes
    | the highest and adds one, so the first ticket raised by hand on the
    | development machine came out as TKT-2026-96851. Nothing broke; the
    | numbers just stopped being a sequence.
    */

    public function test_the_factory_counts_rather_than_inventing(): void
    {
        $tickets = Ticket::factory()->count(5)->create();

        $numbers = $tickets->pluck('ticket_number')->all();

        $this->assertSame(count($numbers), count(array_unique($numbers)), 'Two tickets share a reference.');

        foreach ($numbers as $number) {
            $this->assertMatchesRegularExpression('/^TKT-\d{4}-\d{5}$/', $number);

            // The old version could produce anything up to 99999 from five
            // tickets. A counter cannot.
            $this->assertLessThan(1000, (int) substr($number, -5), "{$number} is not a counted sequence.");
        }
    }

    public function test_a_batch_does_not_hand_out_the_same_number_to_everyone(): void
    {
        /*
         * The trap this design exists for. `count(n)->create()` resolves every
         * definition() BEFORE inserting any of them, so a factory that asked
         * the database for the highest number each time would read the same
         * answer n times and every insert but the first would fail on the
         * unique index.
         */
        $numbers = Ticket::factory()->count(25)->create()->pluck('ticket_number');

        $this->assertCount(25, $numbers->unique(), 'A batch reused a reference.');
    }

    public function test_the_factory_does_not_collide_with_a_ticket_that_already_exists(): void
    {
        /*
         * A real ticket raised before any seeding occupies the first number.
         * A counter starting blindly at 1 would try to take it.
         */
        $first = $this->service()->open($this->attributes(), $this->userWith(UserRole::Assistant));

        $seeded = Ticket::factory()->count(3)->create();

        $this->assertNotContains($first->ticket_number, $seeded->pluck('ticket_number')->all());
        $this->assertSame(4, Ticket::count());
    }

    public function test_the_service_and_the_factory_agree_on_the_format(): void
    {
        // They are the two callers of Ticket::formatNumber(). If one drifts,
        // the demonstration data stops looking like the real thing.
        $fromFactory = Ticket::factory()->create()->ticket_number;
        $fromService = $this->service()->open($this->attributes(), $this->userWith(UserRole::Assistant))->ticket_number;

        $shape = fn (string $n) => preg_replace('/\d/', 'N', $n);

        $this->assertSame($shape($fromFactory), $shape($fromService));
    }

    public function test_an_archived_ticket_does_not_have_its_number_reissued(): void
    {
        /*
         * Two tickets sharing a reference would make the audit trail
         * ambiguous, so a soft-deleted number stays used.
         */
        $reporter = $this->userWith(UserRole::Assistant);

        $first = $this->service()->open($this->attributes(), $reporter);
        $taken = $first->ticket_number;
        $first->delete();

        $next = $this->service()->open($this->attributes(), $reporter);

        $this->assertNotSame($taken, $next->ticket_number);
    }

    // ------------------------------------------------ which asset it is about

    public function test_a_ticket_cannot_name_an_asset_that_does_not_exist(): void
    {
        // `ticketable_id` was once validated as nothing but an integer, and a
        // ticket naming lab PC 999999 was created silently detached.
        $this->actingAs($this->userWith(UserRole::Assistant))
            ->from(route('assistant.tickets.create'))
            ->post(route('assistant.tickets.store'), [
                'title' => 'Will not boot',
                'description' => 'Nothing on screen.',
                'category' => TicketCategory::Hardware->value,
                'priority' => TicketPriority::Medium->value,
                'ticketable_type' => 'lab_pc',
                'ticketable_id' => 999999,
            ])
            ->assertSessionHasErrors('ticketable_id');

        $this->assertSame(0, Ticket::count());
    }

    public function test_a_ticket_cannot_be_raised_against_a_retired_machine(): void
    {
        // Retired kit is excluded from every list, report and risk score, so a
        // ticket against one sits in the queue attached to something nothing
        // else acknowledges.
        $retired = LabPc::factory()->create(['status' => DeviceStatus::Retired->value]);

        $this->actingAs($this->userWith(UserRole::Assistant))
            ->from(route('assistant.tickets.create'))
            ->post(route('assistant.tickets.store'), [
                'title' => 'Will not boot',
                'description' => 'Nothing on screen.',
                'category' => TicketCategory::Hardware->value,
                'priority' => TicketPriority::Medium->value,
                'ticketable_type' => 'lab_pc',
                'ticketable_id' => $retired->id,
            ])
            ->assertSessionHasErrors('ticketable_id');
    }

    public function test_a_fault_about_a_place_rather_than_a_machine_still_works(): void
    {
        // "No network anywhere on Level 6" is a real fault with no asset.
        $this->actingAs($this->userWith(UserRole::Assistant))
            ->post(route('assistant.tickets.store'), [
                'title' => 'No network on Level 6',
                'description' => 'Whole floor is down.',
                'category' => TicketCategory::Network->value,
                'priority' => TicketPriority::Critical->value,
                'location_id' => Location::factory()->create()->id,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, Ticket::count());
        $this->assertNull(Ticket::first()->ticketable_id);
    }
}
