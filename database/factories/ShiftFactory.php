<?php

namespace Database\Factories;

use App\Enums\ShiftStatus;
use App\Models\Location;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * Defaults to a shift scheduled for today that has not been started.
 *
 * A test that cares about check-in must go through ShiftService — a factory
 * writing `checked_in_at` directly proves nothing about whether the rules
 * allow it.
 *
 * @extends Factory<Shift>
 */
class ShiftFactory extends Factory
{
    protected $model = Shift::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'location_id' => Location::factory(),
            'shift_date' => today()->toDateString(),
            'start_time' => '09:00',
            'end_time' => '13:00',
            'status' => ShiftStatus::Scheduled->value,
        ];
    }

    /**
     * A shift happening right now, so check-in is legal.
     *
     * Built from the CAMPUS clock, not `now()`. `start_time` is a wall clock
     * on campus, and the application runs in UTC, so using now() directly
     * produced a window eight hours out and check-in refused during a shift
     * the factory had just declared to be in progress.
     *
     * IT ALSO FREEZES THE CLOCK, and that is not tidiness. This used to build
     * the window as (local now - 1h) to (local now + 2h) and leave the real
     * clock running, which is wrong for about three hours of every day: run
     * the suite at 00:35 campus time and `start_time` becomes 23:35 while
     * `shift_date` is still today, so the shift starts twenty-three hours in
     * the FUTURE and ends before it begins. Every check-in test failed, and
     * only between midnight and 01:00, which is exactly the kind of failure
     * that looks like the database driver or the machine rather than the
     * fixture. A shift that crosses midnight cannot be expressed as one
     * shift_date plus two times, so the answer is to stop generating one.
     *
     * 10:00 is arbitrary and only has to be far enough from both midnights.
     *
     * The framework resets the test clock between tests, so this does not
     * leak into anything else.
     *
     * @see App\Models\Shift::startsAt()
     */
    public function inProgressWindow(): static
    {
        $timezone = config('atlas.calendar.timezone');

        $midMorning = now()->setTimezone($timezone)->setTime(10, 0);

        Carbon::setTestNow($midMorning->copy()->utc());

        return $this->state([
            'shift_date' => $midMorning->toDateString(),
            'start_time' => $midMorning->copy()->subHour()->format('H:i'),
            'end_time' => $midMorning->copy()->addHours(2)->format('H:i'),
        ]);
    }

    public function checkedIn(): static
    {
        return $this->inProgressWindow()->state([
            'status' => ShiftStatus::CheckedIn->value,
            'checked_in_at' => now()->subMinutes(30),
        ]);
    }

    public function on(string $date): static
    {
        return $this->state(['shift_date' => $date]);
    }
}
