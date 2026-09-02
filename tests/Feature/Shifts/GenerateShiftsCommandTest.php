<?php

namespace Tests\Feature\Shifts;

use App\Enums\ShiftStatus;
use App\Enums\UserRole;
use App\Models\Location;
use App\Models\Shift;
use App\Models\ShiftTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * `atlas:generate-shifts` — the command wrapper, not the service behind it.
 *
 * `ShiftService::generateWeek()` was well covered; the command that runs it
 * every Sunday evening was not tested at all. That is the half that actually
 * executes in production, and it owns three things the service does not: which
 * week it picks by default, what it does when a template has been left
 * unassignable, and marking past shifts nobody turned up to.
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

        $wednesday = Carbon::parse('2026-10-07');

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

    public function test_it_marks_past_shifts_nobody_turned_up_to(): void
    {
        /*
         * The other half of what this command does. A roster full of shifts
         * that are neither done nor missed is exactly the kind of stale screen
         * people stop trusting, and nothing else in the system moves a shift
         * out of `scheduled` once its day has gone.
         */
        $this->assistant();
        $this->template();

        $stale = Shift::factory()->create([
            'user_id' => $this->assistant()->id,
            'shift_date' => now()->subWeek(),
            'status' => ShiftStatus::Scheduled,
        ]);

        $this->artisan('atlas:generate-shifts')->assertExitCode(0);

        $this->assertSame(ShiftStatus::Missed, $stale->fresh()->status);
    }

    public function test_a_future_shift_is_not_marked_missed(): void
    {
        // The boundary that matters: today's shift has not been missed yet.
        $this->assistant();

        $upcoming = Shift::factory()->create([
            'user_id' => $this->assistant()->id,
            'shift_date' => now()->addDays(3),
            'status' => ShiftStatus::Scheduled,
        ]);

        $this->artisan('atlas:generate-shifts')->assertExitCode(0);

        $this->assertSame(ShiftStatus::Scheduled, $upcoming->fresh()->status);
    }
}
