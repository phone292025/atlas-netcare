<?php

namespace Tests\Feature\Shifts;

use App\Enums\AlarmType;
use App\Enums\ShiftStatus;
use App\Enums\UserRole;
use App\Models\Alarm;
use App\Models\Shift;
use App\Models\User;
use App\Services\Shifts\ShiftService;
use App\Support\CampusClock;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * FR-408 — overriding an individual shift assignment.
 *
 * The other half of Q4. `generateWeek()` materialises a roster from templates;
 * this is how a real week diverges from the pattern, which it always does.
 * Until 2026-09-02 the only way to change an assignment was to edit the
 * database by hand.
 *
 * Each rule below exists because breaking it loses information, so each has
 * its own test rather than being bundled into one "invalid input" case.
 *
 * @see App\Services\Shifts\ShiftService::reassign()
 * @see docs/modules/M6-shifts-and-alarms.md
 */
class ShiftReassignmentTest extends TestCase
{
    use RefreshDatabase;

    private ShiftService $shifts;

    protected function setUp(): void
    {
        parent::setUp();
        $this->shifts = app(ShiftService::class);
    }

    private function assistant(bool $active = true): User
    {
        $user = User::factory()->create();
        $user->forceFill(['role' => UserRole::Assistant, 'is_active' => $active])->save();

        return $user;
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->forceFill(['role' => UserRole::Admin, 'is_active' => true])->save();

        return $user;
    }

    public function test_a_scheduled_shift_moves_to_another_assistant(): void
    {
        $from = $this->assistant();
        $to = $this->assistant();
        $shift = Shift::factory()->upcoming()->create(['user_id' => $from->id, 'status' => ShiftStatus::Scheduled]);

        $this->shifts->reassign($shift, $to);

        $this->assertSame($to->id, $shift->fresh()->user_id);
    }

    public function test_a_started_shift_cannot_be_reassigned(): void
    {
        /*
         * checked_in_at records that a particular person was physically
         * there. Handing the shift on would attach somebody else's name to
         * work they did not do.
         */
        $shift = Shift::factory()->upcoming()->create([
            'user_id' => $this->assistant()->id,
            'status' => ShiftStatus::CheckedIn,
            'checked_in_at' => now(),
        ]);

        $this->expectException(DomainException::class);

        $this->shifts->reassign($shift, $this->assistant());
    }

    public function test_a_finished_shift_cannot_be_reassigned(): void
    {
        // Same reasoning: completed and missed are both history.
        $shift = Shift::factory()->upcoming()->create([
            'user_id' => $this->assistant()->id,
            'status' => ShiftStatus::Completed,
        ]);

        $this->expectException(DomainException::class);

        $this->shifts->reassign($shift, $this->assistant());
    }

    public function test_a_shift_that_has_ended_cannot_be_reassigned(): void
    {
        /*
         * Still `scheduled`, because nothing had marked it missed yet. Found
         * on the live roster: Monday's shift offered "Reassign to…" on
         * Wednesday, and taking it wrote reminders for a shift already over.
         */
        $shift = Shift::factory()->create([
            'user_id' => $this->assistant()->id,
            'shift_date' => CampusClock::today()->subDay()->toDateString(),
        ]);

        $this->expectException(DomainException::class);

        $this->shifts->reassign($shift, $this->assistant());
    }

    public function test_a_shift_nobody_has_turned_up_to_yet_can_still_be_covered(): void
    {
        // Started, nobody checked in, not over: the assistant is off sick and
        // somebody else can take the rest of it.
        $shift = Shift::factory()->inProgressWindow()->create(['user_id' => $this->assistant()->id]);
        $cover = $this->assistant();

        $this->shifts->reassign($shift, $cover);

        $this->assertSame($cover->id, $shift->fresh()->user_id);
    }

    public function test_the_roster_does_not_offer_to_reassign_a_shift_that_has_ended(): void
    {
        $this->assistant();
        $shift = Shift::factory()->create([
            'user_id' => $this->assistant()->id,
            'shift_date' => CampusClock::today()->subDay()->toDateString(),
        ]);

        $this->actingAs($this->admin())
            ->get(route('admin.roster.index', ['week' => $shift->shift_date->toDateString()]))
            ->assertOk()
            ->assertSee($shift->user->name)
            ->assertDontSee('reassign-'.$shift->id);
    }

    public function test_a_deactivated_account_cannot_be_rostered(): void
    {
        // It would produce a shift nobody can ever check in to, and it would
        // stay invisible until the morning it was needed.
        $shift = Shift::factory()->upcoming()->create(['user_id' => $this->assistant()->id]);

        $this->expectException(DomainException::class);

        $this->shifts->reassign($shift, $this->assistant(active: false));
    }

    public function test_an_engineer_cannot_be_rostered(): void
    {
        $engineer = User::factory()->create();
        $engineer->forceFill(['role' => UserRole::Engineer, 'is_active' => true])->save();

        $shift = Shift::factory()->upcoming()->create(['user_id' => $this->assistant()->id]);

        $this->expectException(DomainException::class);

        $this->shifts->reassign($shift, $engineer);
    }

    public function test_somebody_cannot_be_double_booked(): void
    {
        /*
         * `shifts` has a unique index on (user_id, shift_date, start_time), so
         * without this check a clash surfaces as a raw integrity error rather
         * than a sentence.
         */
        $busy = $this->assistant();
        $shift = Shift::factory()->upcoming()->create(['user_id' => $this->assistant()->id]);

        Shift::factory()->upcoming()->create([
            'user_id' => $busy->id,
            'shift_date' => $shift->shift_date,
            'start_time' => $shift->start_time,
        ]);

        $this->expectException(DomainException::class);

        $this->shifts->reassign($shift, $busy);
    }

    public function test_reassigning_to_the_same_person_is_refused(): void
    {
        // Not harmful, but it would delete and rewrite their alarm for no
        // reason, and the confirmation would claim something happened.
        $person = $this->assistant();
        $shift = Shift::factory()->upcoming()->create(['user_id' => $person->id]);

        $this->expectException(DomainException::class);

        $this->shifts->reassign($shift, $person);
    }

    public function test_the_old_reminder_goes_and_a_new_one_is_written(): void
    {
        /*
         * A pre-shift reminder left pointing at somebody no longer on the
         * shift is worse than no reminder: they turn up, and the person
         * actually rostered does not.
         */
        $from = $this->assistant();
        $to = $this->assistant();
        $shift = Shift::factory()->upcoming()->create(['user_id' => $from->id]);

        Alarm::factory()->pending()->create([
            'user_id' => $from->id,
            'shift_id' => $shift->id,
            'type' => AlarmType::ShiftStart,
        ]);

        $this->shifts->reassign($shift, $to);

        $this->assertSame(0, Alarm::where('shift_id', $shift->id)->where('user_id', $from->id)->count());
        $this->assertSame(1, Alarm::where('shift_id', $shift->id)->where('user_id', $to->id)
            ->where('type', AlarmType::ShiftStart)->count());
    }

    public function test_the_new_assignee_gets_the_inspection_round_reminders_too(): void
    {
        // An eight-hour shift at the default 120-minute interval has three
        // rounds. Reassignment used to rebuild only the start reminder.
        $from = $this->assistant();
        $to = $this->assistant();
        $shift = Shift::factory()->upcoming()->create([
            'user_id' => $from->id,
            'start_time' => '09:00',
            'end_time' => '17:00',
        ]);

        $this->shifts->reassign($shift, $to);

        $this->assertSame(3, Alarm::where('shift_id', $shift->id)->where('user_id', $to->id)
            ->where('type', AlarmType::InspectionRound)->count());
    }

    public function test_a_pending_handover_note_moves_to_the_new_assignee(): void
    {
        // Left by the previous shift at this location, for whoever works this
        // one. Deleting it with the reminders lost the note.
        $from = $this->assistant();
        $to = $this->assistant();
        $shift = Shift::factory()->upcoming()->create(['user_id' => $from->id]);

        $handover = Alarm::factory()->pending()->create([
            'user_id' => $from->id,
            'shift_id' => $shift->id,
            'type' => AlarmType::Handover,
            'message' => 'Printer in lab 3 is jammed.',
        ]);

        $this->shifts->reassign($shift, $to);

        $this->assertSame($to->id, $handover->fresh()->user_id);
    }

    public function test_an_alarm_already_delivered_is_not_erased(): void
    {
        // A sent alarm is a record of something that happened. Deleting it
        // would rewrite history to tidy up the roster.
        $from = $this->assistant();
        $shift = Shift::factory()->upcoming()->create(['user_id' => $from->id]);

        Alarm::factory()->create([
            'user_id' => $from->id,
            'shift_id' => $shift->id,
            'sent_at' => now()->subHour(),
        ]);

        $this->shifts->reassign($shift, $this->assistant());

        $this->assertSame(
            1,
            Alarm::where('shift_id', $shift->id)->where('user_id', $from->id)->whereNotNull('sent_at')->count(),
        );
    }

    public function test_the_new_assignee_is_told_who_they_took_it_from(): void
    {
        $from = $this->assistant();
        $to = $this->assistant();
        $shift = Shift::factory()->upcoming()->create(['user_id' => $from->id]);

        $this->shifts->reassign($shift, $to);

        $this->assertStringContainsString(
            $from->name,
            Alarm::where('user_id', $to->id)->where('type', AlarmType::ShiftStart)->sole()->message,
        );
    }

    public function test_an_admin_can_reassign_from_the_roster_screen(): void
    {
        $from = $this->assistant();
        $to = $this->assistant();
        $shift = Shift::factory()->upcoming()->create(['user_id' => $from->id]);

        $this->actingAs($this->admin())
            ->patch(route('admin.roster.reassign', $shift), ['user_id' => $to->id])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame($to->id, $shift->fresh()->user_id);
    }

    public function test_a_broken_rule_comes_back_as_a_message_not_an_error(): void
    {
        $shift = Shift::factory()->upcoming()->create([
            'user_id' => $this->assistant()->id,
            'status' => ShiftStatus::Completed,
        ]);

        $this->actingAs($this->admin())
            ->patch(route('admin.roster.reassign', $shift), ['user_id' => $this->assistant()->id])
            ->assertRedirect()
            ->assertSessionHas('error');
    }

    public function test_an_assistant_cannot_reassign_anybody(): void
    {
        // The roster is administrator furniture. An assistant swapping their
        // own shift onto somebody else is not a swap, it is a delegation.
        $shift = Shift::factory()->upcoming()->create(['user_id' => $this->assistant()->id]);

        $this->actingAs($this->assistant())
            ->patch(route('admin.roster.reassign', $shift), ['user_id' => $this->assistant()->id])
            ->assertForbidden();
    }
}
