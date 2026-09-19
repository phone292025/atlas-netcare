<?php

namespace Tests\Feature;

use App\Enums\ShiftStatus;
use App\Enums\UserRole;
use App\Models\ChecklistTemplate;
use App\Models\Inspection;
use App\Models\LabPc;
use App\Models\Location;
use App\Models\Shift;
use App\Models\User;
use App\Services\Shifts\ShiftService;
use App\Support\CampusClock;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The application stores time in UTC and must answer calendar questions on the
 * campus clock, eight hours ahead.
 *
 * Every test here freezes the clock at a SPECIFIC hour, because the bugs these
 * guard only exist at particular hours: most of them are invisible from 08:00
 * onwards, which is when UTC's date catches up with the campus date. A suite
 * run in the afternoon would pass all of them against the broken code, which
 * is how they went unnoticed.
 *
 * @see App\Support\CampusClock
 */
class CampusClockTest extends TestCase
{
    use RefreshDatabase;

    private function userWith(UserRole $role): User
    {
        $user = User::factory()->create();
        $user->forceFill(['role' => $role, 'is_active' => true])->save();

        return $user->fresh();
    }

    /** Freeze the application clock at a time read off the campus wall. */
    private function atCampusTime(string $dateTime): void
    {
        Carbon::setTestNow(Carbon::parse($dateTime, CampusClock::timezone()));
    }

    // ------------------------------------------------------ "what day is it"

    public function test_my_shift_finds_todays_shift_before_eight_in_the_morning(): void
    {
        /*
         * THE HEADLINE BUG. At 07:30 on campus it is 23:30 the previous day in
         * UTC, and todayFor() asked UTC. An assistant opening the app at 07:30
         * on a Monday to check their 09:00 shift was told they had none.
         */
        $assistant = $this->userWith(UserRole::Assistant);
        $shift = Shift::factory()->create([
            'user_id' => $assistant->id,
            'shift_date' => '2026-09-21',
            'start_time' => '09:00',
            'end_time' => '13:00',
        ]);

        $this->atCampusTime('2026-09-21 07:30');

        $this->assertSame(
            $shift->id,
            app(ShiftService::class)->todayFor($assistant)?->id,
            'At 07:30 on campus the assistant was told they had no shift today.',
        );

        // And the screen agrees, not only the service.
        $this->actingAs($assistant)
            ->get(route('assistant.shifts.today'))
            ->assertOk()
            ->assertSee('Monday 21 September 2026')
            ->assertDontSee('No shift today');
    }

    /**
     * A shift_date is a calendar date. It must read as "today" all day on
     * campus, including the hours either side of UTC's own midnight.
     *
     * @return array<string, array{string}>
     */
    public static function hoursOfTheCampusDay(): array
    {
        return [
            'just after midnight' => ['2026-09-21 00:05'],
            'before UTC catches up' => ['2026-09-21 07:59'],
            'after UTC catches up' => ['2026-09-21 08:01'],
            'late evening' => ['2026-09-21 23:55'],
        ];
    }

    #[DataProvider('hoursOfTheCampusDay')]
    public function test_a_shift_is_today_for_the_whole_campus_day(string $campusTime): void
    {
        $shift = Shift::factory()->create(['shift_date' => '2026-09-21']);

        $this->atCampusTime($campusTime);

        $this->assertTrue($shift->isToday(), "A shift on 21 September was not 'today' at {$campusTime} on campus.");
    }

    public function test_yesterdays_shift_is_not_upcoming_before_eight_in_the_morning(): void
    {
        /*
         * The direction matters and is easy to get backwards. UTC's date is
         * never AHEAD of the campus date, so asking ">= today in UTC" includes
         * MORE than it should: from midnight until 08:00, yesterday's finished
         * shift was still listed under "Coming up".
         */
        $assistant = $this->userWith(UserRole::Assistant);
        $yesterday = Shift::factory()->create(['user_id' => $assistant->id, 'shift_date' => '2026-09-20']);
        $today = Shift::factory()->create(['user_id' => $assistant->id, 'shift_date' => '2026-09-21']);

        $this->atCampusTime('2026-09-21 06:00');

        $upcoming = Shift::query()->forUser($assistant)->upcoming()->pluck('id');

        $this->assertFalse($upcoming->contains($yesterday->id), 'Yesterday\'s shift was listed as upcoming.');
        $this->assertTrue($upcoming->contains($today->id));
    }

    public function test_a_machine_inspected_after_midnight_counts_as_checked_today(): void
    {
        /*
         * `completed_at` stores a UTC moment. 01:00 on campus is 17:00 UTC the
         * day before, so comparing its UTC DATE to today put this inspection
         * on the wrong day and the machine showed as unchecked.
         */
        ChecklistTemplate::factory()->create(['is_active' => true]);
        $labPc = LabPc::factory()->create();

        $this->atCampusTime('2026-09-21 01:00');

        Inspection::factory()->completed()->create([
            'lab_pc_id' => $labPc->id,
            'checklist_template_id' => ChecklistTemplate::current()->id,
            'completed_at' => now(),
        ]);

        $this->atCampusTime('2026-09-21 09:00');

        // The marker element itself. The card subtitle always reads "Already
        // checked today are marked", so asserting the bare words matches the
        // subtitle and can never fail.
        $this->actingAs($this->userWith(UserRole::Assistant))
            ->get(route('assistant.inspections.create', ['location' => $labPc->location_id]))
            ->assertOk()
            ->assertSee('<span class="text-emerald-600">checked today</span>', false);
    }

    // ------------------------------------------------ what a person is shown

    public function test_a_check_in_is_shown_on_the_campus_clock(): void
    {
        /*
         * An assistant checked in at 08:55 on campus, which is stored as 00:55
         * UTC. The screen printed the stored value, so it read "Checked in at
         * 00:55" beside "Opens 08:45" — two clocks on one screen, and a check-in
         * that appeared to happen eight hours before the shift opened.
         */
        $assistant = $this->userWith(UserRole::Assistant);

        $this->atCampusTime('2026-09-21 08:55');
        $checkedIn = now();

        Shift::factory()->create([
            'user_id' => $assistant->id,
            'shift_date' => '2026-09-21',
            'start_time' => '09:00',
            'end_time' => '13:00',
            'status' => ShiftStatus::CheckedIn->value,
            'checked_in_at' => $checkedIn,
        ]);

        $this->atCampusTime('2026-09-21 10:00');

        $this->actingAs($assistant)
            ->get(route('assistant.shifts.today'))
            ->assertOk()
            ->assertSee('Checked in at 08:55')
            ->assertDontSee('Checked in at 00:55');
    }

    public function test_the_traffic_filter_keeps_the_dates_it_was_given(): void
    {
        /*
         * The range goes to the query as UTC and back to the page. If the date
         * inputs printed the UTC value, campus midnight on the 12th would come
         * back as the 11th, and every press of Apply would move the range a day
         * earlier.
         */
        Location::factory()->create();

        $this->atCampusTime('2026-09-20 10:00');

        $this->actingAs($this->userWith(UserRole::Engineer))
            ->get(route('engineer.traffic.index', ['from' => '2026-09-12', 'to' => '2026-09-19']))
            ->assertOk()
            ->assertSee('value="2026-09-12"', false)
            ->assertSee('value="2026-09-19"', false)
            ->assertSee('12 Sep 2026 to 19 Sep 2026');
    }

    // --------------------------------------------------------- the scheduler

    /**
     * @return array<string, array{string, string}>
     */
    public static function overnightJobs(): array
    {
        return [
            'risk scoring' => ['atlas:recalculate-risk', '0 2 * * *'],
            'next week\'s roster' => ['atlas:generate-shifts', '0 18 * * 0'],
            'telemetry retention' => ['atlas:prune-telemetry', '15 3 * * 0'],
        ];
    }

    #[DataProvider('overnightJobs')]
    public function test_a_timed_job_runs_on_the_campus_clock(string $command, string $expression): void
    {
        /*
         * Without a zone the scheduler reads every time in UTC. "Daily at
         * 02:00" for risk scoring ran at 10:00 on campus, in the middle of the
         * working day it was meant to finish before.
         */
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event) => str_contains($event->command ?? '', $command));

        $this->assertNotNull($event, "{$command} is not scheduled.");
        $this->assertSame($expression, $event->expression);
        $this->assertSame(CampusClock::timezone(), $event->timezone, "{$command} reads its time in UTC.");
    }

    // ----------------------------------------------------- storage untouched

    public function test_moments_are_still_stored_in_utc(): void
    {
        /*
         * The fix is to DISPLAY and to calendar questions only. Storage staying
         * UTC is what keeps every existing row correct and every comparison
         * between two moments free of timezone arithmetic.
         */
        $this->atCampusTime('2026-09-21 08:55');

        $shift = Shift::factory()->create(['checked_in_at' => now()]);

        $this->assertSame(
            '2026-09-21 00:55:00',
            $shift->fresh()->getRawOriginal('checked_in_at'),
            'A moment was written to the database on the campus clock instead of UTC.',
        );
    }
}
