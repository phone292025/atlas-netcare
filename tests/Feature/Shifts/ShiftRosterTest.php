<?php

namespace Tests\Feature\Shifts;

use App\Enums\AlarmType;
use App\Enums\ShiftStatus;
use App\Enums\UserRole;
use App\Models\Alarm;
use App\Models\Location;
use App\Models\Shift;
use App\Models\ShiftTemplate;
use App\Models\User;
use App\Services\Shifts\ShiftService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * M6 — generating the roster, and working a shift.
 *
 * @see App\Services\Shifts\ShiftService
 * @see docs/04-open-questions.md Q4
 */
class ShiftRosterTest extends TestCase
{
    use RefreshDatabase;

    private function userWith(UserRole $role): User
    {
        $user = User::factory()->create();
        $user->forceFill(['role' => $role, 'is_active' => true])->save();

        return $user->fresh();
    }

    private function service(): ShiftService
    {
        return app(ShiftService::class);
    }

    // ------------------------------------------------------------ generating

    public function test_a_template_generates_a_shift_on_its_weekday(): void
    {
        $this->userWith(UserRole::Assistant);
        // 1 = Monday, matching Carbon::dayOfWeek.
        ShiftTemplate::factory()->onDay(1)->create();

        $week = now()->addWeek()->startOfWeek();
        $created = $this->service()->generateWeek($week);

        $this->assertCount(1, $created);
        // The whole roster shifts by six days if the day-of-week conversion is
        // wrong, and it looks like the generator simply not working.
        $this->assertTrue($created->first()->shift_date->isMonday());
        $this->assertSame($week->toDateString(), $created->first()->shift_date->toDateString());
    }

    public function test_sunday_lands_at_the_end_of_the_week_not_the_start(): void
    {
        /*
         * The off-by-six. Carbon numbers Sunday as 0 but startOfWeek() is
         * Monday, so a naive addDays($dayOfWeek) puts Sunday's shift on the
         * Monday.
         */
        $this->userWith(UserRole::Assistant);
        ShiftTemplate::factory()->onDay(0)->create();

        $created = $this->service()->generateWeek(now()->addWeek()->startOfWeek());

        $this->assertTrue($created->first()->shift_date->isSunday());
    }

    public function test_a_slot_needing_two_assistants_generates_two_shifts(): void
    {
        $this->userWith(UserRole::Assistant);
        $this->userWith(UserRole::Assistant);
        ShiftTemplate::factory()->needing(2)->create();

        $created = $this->service()->generateWeek(now()->addWeek()->startOfWeek());

        $this->assertCount(2, $created);
        $this->assertSame(2, $created->pluck('user_id')->unique()->count());
    }

    public function test_nobody_is_rostered_twice_in_the_same_slot(): void
    {
        // Only one assistant for a slot needing two. Under-filling is honest;
        // double-booking is not.
        $this->userWith(UserRole::Assistant);
        ShiftTemplate::factory()->needing(2)->create();

        $created = $this->service()->generateWeek(now()->addWeek()->startOfWeek());

        $this->assertCount(1, $created);
    }

    public function test_generating_the_same_week_twice_adds_nothing(): void
    {
        /*
         * It is scheduled AND there is a button for it, so it will be run
         * twice. Without the existence check a second pass rosters everybody
         * again.
         */
        $this->userWith(UserRole::Assistant);
        ShiftTemplate::factory()->create();

        $week = now()->addWeek()->startOfWeek();
        $this->service()->generateWeek($week);
        $this->service()->generateWeek($week);

        $this->assertSame(1, Shift::count());
    }

    public function test_inactive_templates_are_skipped(): void
    {
        $this->userWith(UserRole::Assistant);
        ShiftTemplate::factory()->inactive()->create();

        $this->assertCount(0, $this->service()->generateWeek(now()->addWeek()->startOfWeek()));
    }

    public function test_generating_without_assistants_is_reported_not_crashed(): void
    {
        // A real state on a fresh install: templates exist but nobody has the
        // assistant role yet.
        ShiftTemplate::factory()->create();

        $this->expectException(DomainException::class);

        $this->service()->generateWeek(now()->addWeek()->startOfWeek());
    }

    public function test_work_is_spread_rather_than_piled_on_one_person(): void
    {
        // Assigning in id order gives the first assistant every slot.
        $this->userWith(UserRole::Assistant);
        $this->userWith(UserRole::Assistant);

        $location = Location::factory()->create();
        foreach ([1, 2, 3, 4] as $day) {
            ShiftTemplate::factory()->onDay($day)->create(['location_id' => $location->id]);
        }

        $created = $this->service()->generateWeek(now()->addWeek()->startOfWeek());

        $this->assertSame(4, $created->count());
        $this->assertSame(2, $created->pluck('user_id')->unique()->count());
    }

    public function test_times_are_copied_onto_the_shift_not_read_through_the_template(): void
    {
        /*
         * Changing "Mondays 09:00" to 10:00 must not rewrite what somebody
         * already worked last week.
         */
        $this->userWith(UserRole::Assistant);
        $template = ShiftTemplate::factory()->create(['start_time' => '09:00', 'end_time' => '13:00']);

        $shift = $this->service()->generateWeek(now()->addWeek()->startOfWeek())->first();

        $template->update(['start_time' => '10:00']);

        $this->assertSame('09:00', substr($shift->fresh()->start_time, 0, 5));
    }

    // ------------------------------------------------------ scheduled alarms

    public function test_generating_a_shift_schedules_its_reminders(): void
    {
        /*
         * The reason alarms carry `trigger_at` rather than being written when
         * they fire: the shift exists a week early, so its reminders can too.
         */
        $this->userWith(UserRole::Assistant);
        ShiftTemplate::factory()->create(['start_time' => '09:00', 'end_time' => '13:00']);

        $this->service()->generateWeek(now()->addWeek()->startOfWeek());

        $this->assertSame(1, Alarm::where('type', AlarmType::ShiftStart)->count());
        // A four-hour shift at a 120-minute interval gets one round reminder,
        // at the halfway point — the one at the very end is skipped.
        $this->assertSame(1, Alarm::where('type', AlarmType::InspectionRound)->count());
    }

    public function test_scheduled_alarms_are_not_visible_before_they_are_due(): void
    {
        $this->userWith(UserRole::Assistant);
        ShiftTemplate::factory()->create();

        $this->service()->generateWeek(now()->addWeek()->startOfWeek());

        // Written, but not sent — so not countable and not on screen.
        $this->assertGreaterThan(0, Alarm::count());
        $this->assertSame(0, Alarm::whereNotNull('sent_at')->count());
    }

    // ------------------------------------------------------ check in and out

    public function test_an_assistant_can_check_in_during_their_shift(): void
    {
        $assistant = $this->userWith(UserRole::Assistant);
        $shift = Shift::factory()->inProgressWindow()->create(['user_id' => $assistant->id]);

        $this->service()->checkIn($shift, $assistant);

        $this->assertSame(ShiftStatus::CheckedIn, $shift->fresh()->status);
        $this->assertNotNull($shift->fresh()->checked_in_at);
    }

    public function test_an_assistant_cannot_check_in_to_somebody_elses_shift(): void
    {
        $shift = Shift::factory()->inProgressWindow()->create([
            'user_id' => $this->userWith(UserRole::Assistant)->id,
        ]);

        $this->expectException(DomainException::class);

        $this->service()->checkIn($shift, $this->userWith(UserRole::Assistant));
    }

    public function test_an_assistant_cannot_check_in_to_a_future_shift(): void
    {
        // Otherwise attendance means nothing — anyone could check in to next
        // Friday from their sofa.
        $assistant = $this->userWith(UserRole::Assistant);
        $shift = Shift::factory()->create([
            'user_id' => $assistant->id,
            'shift_date' => today()->addWeek()->toDateString(),
        ]);

        $this->expectException(DomainException::class);

        $this->service()->checkIn($shift, $assistant);
    }

    public function test_checking_in_twice_is_refused(): void
    {
        $assistant = $this->userWith(UserRole::Assistant);
        $shift = Shift::factory()->checkedIn()->create(['user_id' => $assistant->id]);

        $this->expectException(DomainException::class);

        $this->service()->checkIn($shift, $assistant);
    }

    public function test_checking_out_records_the_handover(): void
    {
        $assistant = $this->userWith(UserRole::Assistant);
        $shift = Shift::factory()->checkedIn()->create(['user_id' => $assistant->id]);

        $this->service()->checkOut($shift, $assistant, 'Printer in L6 still jamming.');

        $fresh = $shift->fresh();

        $this->assertSame(ShiftStatus::Completed, $fresh->status);
        $this->assertNotNull($fresh->checked_out_at);
        $this->assertSame('Printer in L6 still jamming.', $fresh->handover_notes);
    }

    public function test_a_handover_reaches_the_next_shift_in_that_location(): void
    {
        /*
         * Handover notes nobody reads are just a diary. The next person on
         * gets an alarm when their shift starts — not now, because telling
         * somebody at 13:00 about a shift they begin tomorrow is how it gets
         * forgotten.
         */
        $location = Location::factory()->create();
        $leaving = $this->userWith(UserRole::Assistant);
        $arriving = $this->userWith(UserRole::Assistant);

        $shift = Shift::factory()->checkedIn()->create([
            'user_id' => $leaving->id, 'location_id' => $location->id,
        ]);
        Shift::factory()->create([
            'user_id' => $arriving->id,
            'location_id' => $location->id,
            'shift_date' => today()->addDay()->toDateString(),
        ]);

        $this->service()->checkOut($shift, $leaving, 'Printer in L6 still jamming.');

        $handover = Alarm::where('type', AlarmType::Handover)->first();

        $this->assertNotNull($handover);
        $this->assertSame($arriving->id, $handover->user_id);
        $this->assertSame('Printer in L6 still jamming.', $handover->message);
    }

    public function test_checking_out_without_notes_raises_no_handover(): void
    {
        $assistant = $this->userWith(UserRole::Assistant);
        $shift = Shift::factory()->checkedIn()->create(['user_id' => $assistant->id]);

        $this->service()->checkOut($shift, $assistant, null);

        $this->assertSame(0, Alarm::where('type', AlarmType::Handover)->count());
    }

    public function test_you_cannot_check_out_of_a_shift_you_never_started(): void
    {
        $assistant = $this->userWith(UserRole::Assistant);
        $shift = Shift::factory()->inProgressWindow()->create(['user_id' => $assistant->id]);

        $this->expectException(DomainException::class);

        $this->service()->checkOut($shift, $assistant, 'Pretending I was here.');
    }

    // ------------------------------------------------------------ missed

    public function test_a_shift_nobody_turned_up_to_is_marked_missed(): void
    {
        // A status rather than an absence of one, so the roster can show it
        // and a report can count it.
        $shift = Shift::factory()->create([
            'user_id' => $this->userWith(UserRole::Assistant)->id,
            'shift_date' => today()->subDay()->toDateString(),
        ]);

        $this->assertSame(1, $this->service()->markMissed());
        $this->assertSame(ShiftStatus::Missed, $shift->fresh()->status);
    }

    public function test_a_completed_shift_is_not_marked_missed(): void
    {
        $assistant = $this->userWith(UserRole::Assistant);
        $shift = Shift::factory()->checkedIn()->create(['user_id' => $assistant->id]);
        $this->service()->checkOut($shift, $assistant);

        $this->service()->markMissed();

        $this->assertSame(ShiftStatus::Completed, $shift->fresh()->status);
    }

    public function test_a_shift_still_to_come_is_not_marked_missed(): void
    {
        $shift = Shift::factory()->create([
            'user_id' => $this->userWith(UserRole::Assistant)->id,
            'shift_date' => today()->addDay()->toDateString(),
        ]);

        $this->service()->markMissed();

        $this->assertSame(ShiftStatus::Scheduled, $shift->fresh()->status);
    }
}
