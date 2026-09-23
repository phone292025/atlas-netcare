<?php

namespace Tests\Feature\Shifts;

use App\Enums\ShiftStatus;
use App\Enums\UserRole;
use App\Models\Shift;
use App\Models\User;
use App\Support\CampusClock;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * `atlas:mark-missed-shifts`, and that it runs often enough to matter.
 *
 * It used to run only with the Sunday roster, so Monday's no-show said
 * "Scheduled" all week and the roster offered to reassign it.
 *
 * @see app/Console/Commands/MarkMissedShifts.php
 * @see routes/console.php
 */
class MarkMissedShiftsCommandTest extends TestCase
{
    use RefreshDatabase;

    private function shiftOn(string $date, string $start = '09:00', string $end = '13:00'): Shift
    {
        $user = User::factory()->create();
        $user->forceFill(['role' => UserRole::Assistant, 'is_active' => true])->save();

        return Shift::factory()->create([
            'user_id' => $user->id,
            'shift_date' => $date,
            'start_time' => $start,
            'end_time' => $end,
        ]);
    }

    public function test_it_marks_past_shifts_nobody_turned_up_to(): void
    {
        $stale = $this->shiftOn(CampusClock::today()->subWeek()->toDateString());

        $this->artisan('atlas:mark-missed-shifts')->assertSuccessful();

        $this->assertSame(ShiftStatus::Missed, $stale->fresh()->status);
    }

    public function test_a_shift_that_ended_earlier_today_is_marked(): void
    {
        // The case the weekly run left for days: this morning's shift, by
        // mid-afternoon.
        $this->travelTo(Carbon::parse('2026-09-23 15:00', CampusClock::timezone()));
        $morning = $this->shiftOn('2026-09-23', '09:00', '13:00');
        $evening = $this->shiftOn('2026-09-23', '17:00', '21:00');

        $this->artisan('atlas:mark-missed-shifts')->assertSuccessful();

        $this->assertSame(ShiftStatus::Missed, $morning->fresh()->status);
        $this->assertSame(ShiftStatus::Scheduled, $evening->fresh()->status, 'Not over yet.');
    }

    public function test_a_future_shift_is_not_marked_missed(): void
    {
        $upcoming = $this->shiftOn(CampusClock::today()->addDays(3)->toDateString());

        $this->artisan('atlas:mark-missed-shifts')->assertSuccessful();

        $this->assertSame(ShiftStatus::Scheduled, $upcoming->fresh()->status);
    }

    public function test_it_is_scheduled_every_five_minutes(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event) => str_contains($event->command ?? '', 'atlas:mark-missed-shifts'));

        $this->assertNotNull($event, 'atlas:mark-missed-shifts is not scheduled.');
        $this->assertSame('*/5 * * * *', $event->expression);
    }
}
