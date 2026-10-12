<?php

namespace Tests\Feature\Shifts;

use App\Enums\UserRole;
use App\Models\Location;
use App\Models\Shift;
use App\Models\ShiftTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `atlas:generate-shifts` — the command wrapper, not the service behind it.
 *
 * `ShiftService::generateWeek()` was well covered; the command that runs it
 * every Sunday evening was not tested at all. That is the half that actually
 * executes in production, and it owns two things the service does not: which
 * week it picks by default, and what it does when a template has been left
 * unassignable. Marking missed shifts is atlas:mark-missed-shifts.
 *
 * @see app/Console/Commands/GenerateShifts.php
 * @see routes/console.php
 */
class GenerateShiftsCommandTest extends TestCase
{
    use RefreshDatabase;

    private function assistant(): User
    {
        $user = User::factory()->create();
        $user->forceFill(['role' => UserRole::Assistant, 'is_active' => true])->save();

        return $user;
    }

    private function template(): ShiftTemplate
    {
        return ShiftTemplate::factory()->create([
            'location_id' => Location::factory()->create()->id,
            'day_of_week' => 1,
            'start_time' => '09:00:00',
            'end_time' => '13:00:00',
            'is_active' => true,
        ]);
    }

    public function test_it_generates_next_week_by_default(): void
    {
        // Scheduled for Sunday evening so the roster is populated before
        // anybody looks on Monday — which means "next week", not this one.
        $this->assistant();
        $this->template();

        $this->artisan('atlas:generate-shifts')->assertExitCode(0);

        $shift = Shift::firstOrFail();

        $this->assertTrue(
            $shift->shift_date->greaterThanOrEqualTo(now()->startOfWeek()->addWeek()),
            'The default week must be the one after this one.',
        );
    }

    public function test_a_named_week_is_honoured_and_snapped_to_its_monday(): void
    {
        // The generator works in whole weeks. A roster starting on a Wednesday
        // would silently produce a different set of days from the one asked
        // for.
        $this->assistant();
        $this->template();

        // Relative, not a fixed date: the generator skips slots that have
        // already started, so a hard-coded 2026-10-07 stopped producing any
        // shifts the day that week began.
        $wednesday = now()->addWeeks(2)->startOfWeek()->addDays(2);

        $this->artisan('atlas:generate-shifts', ['--week' => $wednesday->toDateString()])
            ->assertExitCode(0);

        $this->assertSame(
            $wednesday->copy()->startOfWeek()->toDateString(),
            Shift::firstOrFail()->shift_date->copy()->startOfWeek()->toDateString(),
        );
    }

    public function test_running_it_twice_adds_nothing(): void
    {
        /*
         * It is scheduled AND there is a button for it, so it will be run
         * twice. A second pass must not roster everybody again — the unique
         * index on (user_id, shift_date, start_time) would refuse anyway, and
         * an integrity error is a poor way to find that out.
         */
        $this->assistant();
        $this->template();

        $this->artisan('atlas:generate-shifts')->assertExitCode(0);
        $after = Shift::count();

        $this->artisan('atlas:generate-shifts')->assertExitCode(0);

        $this->assertSame($after, Shift::count());
    }

    public function test_it_reports_rather_than_throws_when_there_is_nobody_to_roster(): void
    {
        /*
         * A real state: templates exist, every assistant has been deactivated.
         * The service throws a DomainException, and a scheduled command that
         * dies on an expected condition fills the log with stack traces and
         * teaches people to ignore it.
         */
        $this->template();

        $this->artisan('atlas:generate-shifts')->assertExitCode(1);

        $this->assertSame(0, Shift::count());
    }

    public function test_it_survives_having_no_templates_at_all(): void
    {
        // The state on a fresh install, before an administrator has set any
        // shift patterns up.
        $this->assistant();

        $this->artisan('atlas:generate-shifts')->assertExitCode(0);

        $this->assertSame(0, Shift::count());
    }

    public function test_running_it_twice_adds_nothing_even_with_spare_assistants(): void
    {
        /*
         * The version of this above uses ONE assistant, and passed while the
         * method was not actually idempotent.
         *
         * The existence check is per PERSON: it stops one assistant being
         * double-booked, and does nothing about the SLOT being filled twice.
         * With a spare assistant available, a second run simply picked them
         * and created a duplicate. Found on the live roster, where a template
         * needing one assistant had two shifts on the same Monday morning.
         *
         * Three assistants and one slot, so there is always somebody spare to
         * be wrongly chosen.
         */
        $this->assistant();
        $this->assistant();
        $this->assistant();
        $this->template();

        $this->artisan('atlas:generate-shifts')->assertExitCode(0);
        $after = Shift::count();

        $this->assertSame(1, $after, 'One template needing one assistant is one shift.');

        $this->artisan('atlas:generate-shifts')->assertExitCode(0);
        $this->artisan('atlas:generate-shifts')->assertExitCode(0);

        $this->assertSame($after, Shift::count(), 'Re-running must not fill the slot again.');
    }

    public function test_a_slot_needing_two_people_gets_exactly_two(): void
    {
        // The other half: under-filling would be just as wrong as duplicating.
        $this->assistant();
        $this->assistant();
        $this->assistant();

        $template = $this->template();
        $template->forceFill(['required_assistants' => 2])->save();

        $this->artisan('atlas:generate-shifts')->assertExitCode(0);

        $this->assertSame(2, Shift::count());

        $this->artisan('atlas:generate-shifts')->assertExitCode(0);

        $this->assertSame(2, Shift::count());
    }

    public function test_a_slot_is_topped_up_rather_than_refilled(): void
    {
        /*
         * Somebody deletes a shift, or an assistant is removed from one, and
         * the roster is regenerated. It should fill the gap and nothing else.
         */
        $this->assistant();
        $this->assistant();
        $this->assistant();

        $template = $this->template();
        $template->forceFill(['required_assistants' => 3])->save();

        $this->artisan('atlas:generate-shifts');
        $this->assertSame(3, Shift::count());

        Shift::latest('id')->first()->delete();
        $this->assertSame(2, Shift::count());

        $this->artisan('atlas:generate-shifts');

        $this->assertSame(3, Shift::count(), 'The gap should be filled, and only the gap.');
    }
}
