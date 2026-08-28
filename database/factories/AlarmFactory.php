<?php

namespace Database\Factories;

use App\Enums\AlarmType;
use App\Models\Alarm;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Defaults to an alarm already sent and not yet read — the state the unread
 * badge counts.
 *
 * @extends Factory<Alarm>
 */
class AlarmFactory extends Factory
{
    protected $model = Alarm::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'type' => AlarmType::ShiftStart->value,
            'title' => 'Your shift starts soon',
            'message' => 'Level 6 IT Lab 01, Mon 1 Sep, 09:00-13:00',
            'trigger_at' => now()->subMinutes(5),
            'sent_at' => now()->subMinutes(5),
            'read_at' => null,
        ];
    }

    /** Scheduled for later — exists, but must not be visible or counted yet. */
    public function pending(): static
    {
        return $this->state([
            'trigger_at' => now()->addHours(2),
            'sent_at' => null,
        ]);
    }

    public function read(): static
    {
        return $this->state(['read_at' => now()]);
    }

    public function ofType(AlarmType $type): static
    {
        return $this->state(['type' => $type->value]);
    }
}
