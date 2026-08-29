<?php

namespace Database\Factories;

use App\Enums\ShiftStatus;
use App\Models\Location;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

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
     * @see App\Models\Shift::startsAt()
     */
    public function inProgressWindow(): static
    {
        $local = now()->setTimezone(config('atlas.calendar.timezone'));

        return $this->state([
            'shift_date' => $local->toDateString(),
            'start_time' => $local->copy()->subHour()->format('H:i'),
            'end_time' => $local->copy()->addHours(2)->format('H:i'),
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
