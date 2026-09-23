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
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * FR-408 — taking shifts off the roster, one or a week at a time.
 *
 * Only before a shift starts. From then on it is attendance, and a roster
 * that could delete a no-show would make the missed-shift count worthless.
 *
 * @see App\Services\Shifts\ShiftService::remove()
 * @see docs/modules/M6-shifts-and-alarms.md
 */
class ShiftRemovalTest extends TestCase
{
    use RefreshDatabase;

    private ShiftService $shifts;

    protected function setUp(): void
    {
        parent::setUp();
        $this->shifts = app(ShiftService::class);
    }

    private function userWith(UserRole $role): User
    {
        $user = User::factory()->create();
        $user->forceFill(['role' => $role, 'is_active' => true])->save();

        return $user;
    }

    private function upcomingShift(): Shift
    {
        return Shift::factory()->upcoming()->create(['user_id' => $this->userWith(UserRole::Assistant)->id]);
    }

    public function test_a_shift_that_has_not_started_is_removed(): void
    {
        $shift = $this->upcomingShift();

        $this->shifts->remove($shift);

        $this->assertModelMissing($shift);
    }

    public function test_the_assistant_is_told_and_their_reminders_are_cancelled(): void
    {
        // Otherwise the reminder still arrives, and they come in for nothing.
        $shift = $this->upcomingShift();
        Alarm::factory()->pending()->create([
            'user_id' => $shift->user_id,
            'shift_id' => $shift->id,
            'type' => AlarmType::ShiftStart,
        ]);

        $this->shifts->remove($shift);

        $alarms = Alarm::where('user_id', $shift->user_id)->get();

        $this->assertSame([AlarmType::ShiftRemoved], $alarms->pluck('type')->all());
        $this->assertStringContainsString($shift->label(), $alarms->sole()->message);
    }

    public function test_an_alarm_already_delivered_is_kept(): void
    {
        // As in reassign(): a sent alarm records something that happened.
        // The foreign key cascades, so it has to be detached first.
        $shift = $this->upcomingShift();
        $sent = Alarm::factory()->create([
            'user_id' => $shift->user_id,
            'shift_id' => $shift->id,
            'sent_at' => now()->subHour(),
        ]);

        $this->shifts->remove($shift);

        $this->assertNull($sent->fresh()?->shift_id);
        $this->assertModelExists($sent);
    }

    public function test_a_pending_handover_note_moves_to_the_next_shift_in_that_place(): void
    {
        $shift = $this->upcomingShift();
        $next = Shift::factory()->create([
            'user_id' => $this->userWith(UserRole::Assistant)->id,
            'location_id' => $shift->location_id,
            'shift_date' => $shift->shift_date->copy()->addDay()->toDateString(),
        ]);

        Alarm::factory()->pending()->create([
            'user_id' => $shift->user_id,
            'shift_id' => $shift->id,
            'type' => AlarmType::Handover,
            'message' => 'Printer in lab 3 is jammed.',
        ]);

        $this->shifts->remove($shift);

        $handover = Alarm::where('type', AlarmType::Handover)->sole();

        $this->assertSame($next->id, $handover->shift_id);
        $this->assertSame($next->user_id, $handover->user_id);
        $this->assertSame('Printer in lab 3 is jammed.', $handover->message);
    }

    public function test_a_started_shift_cannot_be_removed_even_if_nobody_came(): void
    {
        // Nobody checking in is exactly what "missed" records.
        $shift = Shift::factory()->inProgressWindow()->create([
            'user_id' => $this->userWith(UserRole::Assistant)->id,
        ]);

        $this->expectException(DomainException::class);

        $this->shifts->remove($shift);
    }

    public function test_a_missed_shift_cannot_be_removed(): void
    {
        $shift = Shift::factory()->create([
            'user_id' => $this->userWith(UserRole::Assistant)->id,
            'shift_date' => CampusClock::today()->subDay()->toDateString(),
            'status' => ShiftStatus::Missed,
        ]);

        $this->expectException(DomainException::class);

        $this->shifts->remove($shift);
    }

    public function test_clearing_a_week_keeps_what_has_started(): void
    {
        // Wednesday mid-morning: Monday is history, this morning's shift is
        // under way, Friday's has not started.
        $this->travelTo(Carbon::parse('2026-09-23 10:30', CampusClock::timezone()));
        $shift = fn (string $date, ShiftStatus $status = ShiftStatus::Scheduled) => Shift::factory()->create([
            'user_id' => $this->userWith(UserRole::Assistant)->id,
            'shift_date' => $date,
            'status' => $status,
        ]);

        $monday = $shift('2026-09-21', ShiftStatus::Missed);
        $today = $shift('2026-09-23');
        $friday = $shift('2026-09-25');

        $result = $this->shifts->clearWeek(Carbon::parse('2026-09-21'));

        $this->assertSame(['removed' => 1, 'kept' => 2], $result);
        $this->assertModelMissing($friday);
        $this->assertModelExists($monday);
        $this->assertModelExists($today);
    }

    // ------------------------------------------------------------ the screen

    public function test_an_admin_can_remove_a_shift_from_the_roster_screen(): void
    {
        $shift = $this->upcomingShift();

        $this->actingAs($this->userWith(UserRole::Admin))
            ->delete(route('admin.roster.destroy', $shift))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertModelMissing($shift);
    }

    public function test_a_broken_rule_comes_back_as_a_message_not_an_error(): void
    {
        $shift = Shift::factory()->create([
            'user_id' => $this->userWith(UserRole::Assistant)->id,
            'status' => ShiftStatus::Completed,
        ]);

        $this->actingAs($this->userWith(UserRole::Admin))
            ->delete(route('admin.roster.destroy', $shift))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertModelExists($shift);
    }

    public function test_an_admin_can_clear_the_week_on_screen(): void
    {
        $shift = $this->upcomingShift();
        $week = $shift->shift_date->copy()->startOfWeek()->toDateString();

        $this->actingAs($this->userWith(UserRole::Admin))
            ->delete(route('admin.roster.clear'), ['week' => $week])
            ->assertRedirect(route('admin.roster.index', ['week' => $week]))
            ->assertSessionHas('success');

        $this->assertModelMissing($shift);
    }

    public function test_the_roster_offers_remove_only_before_a_shift_starts(): void
    {
        $upcoming = $this->upcomingShift();
        $ended = Shift::factory()->create([
            'user_id' => $this->userWith(UserRole::Assistant)->id,
            'shift_date' => CampusClock::today()->subDay()->toDateString(),
        ]);

        $admin = $this->userWith(UserRole::Admin);

        $this->actingAs($admin)
            ->get(route('admin.roster.index', ['week' => $upcoming->shift_date->toDateString()]))
            ->assertSee('confirm-remove-'.$upcoming->id);

        $this->actingAs($admin)
            ->get(route('admin.roster.index', ['week' => $ended->shift_date->toDateString()]))
            ->assertSee($ended->user->name)
            ->assertDontSee('confirm-remove-'.$ended->id);
    }

    public function test_an_assistant_cannot_remove_anybody(): void
    {
        $shift = $this->upcomingShift();

        $this->actingAs($this->userWith(UserRole::Assistant))
            ->delete(route('admin.roster.destroy', $shift))
            ->assertForbidden();

        $this->assertModelExists($shift);
    }
}
