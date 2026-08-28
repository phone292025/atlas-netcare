<?php

namespace Database\Factories;

use App\Enums\ChecklistCategory;
use App\Models\ChecklistItem;
use App\Models\ChecklistTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChecklistItem>
 */
class ChecklistItemFactory extends Factory
{
    protected $model = ChecklistItem::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'checklist_template_id' => ChecklistTemplate::factory(),
            'label' => $this->faker->randomElement([
                'Machine powers on and reaches the desktop',
                'Monitor displays without flicker',
                'Keyboard and mouse respond',
                'Wired network connects',
            ]),
            'category' => $this->faker->randomElement(ChecklistCategory::values()),
            'is_critical' => false,
            'sort_order' => $this->faker->numberBetween(1, 20),
        ];
    }

    /** A critical item failing turns the whole inspection into a `fail`. */
    public function critical(): static
    {
        return $this->state(['is_critical' => true]);
    }
}
