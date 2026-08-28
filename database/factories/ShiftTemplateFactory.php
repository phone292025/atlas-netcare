<?php

namespace Database\Factories;

use App\Models\Location;
use App\Models\ShiftTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ShiftTemplate> */
class ShiftTemplateFactory extends Factory
{
    protected $model = ShiftTemplate::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => 'Morning lab cover',
            // 1 = Monday. 0 is Sunday, matching Carbon::dayOfWeek.
            'day_of_week' => 1,
            'start_time' => '09:00',
            'end_time' => '13:00',
            'location_id' => Location::factory(),
            'required_assistants' => 1,
            'is_active' => true,
        ];
    }

    public function onDay(int $dayOfWeek): static
    {
        return $this->state(['day_of_week' => $dayOfWeek]);
    }

    public function needing(int $assistants): static
    {
        return $this->state(['required_assistants' => $assistants]);
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
