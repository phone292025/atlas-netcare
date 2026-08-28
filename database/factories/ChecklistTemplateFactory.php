<?php

namespace Database\Factories;

use App\Models\ChecklistTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Checklist templates for tests.
 *
 * The real one is written out in ReferenceSeeder — a checklist is reference
 * data a deployment needs on day one, not demo data.
 *
 * @extends Factory<ChecklistTemplate>
 */
class ChecklistTemplateFactory extends Factory
{
    protected $model = ChecklistTemplate::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => 'Standard Lab PC Check',
            'description' => 'Routine check carried out during a lab round.',
            'version' => 1,
            'is_active' => false,
        ];
    }

    /**
     * The template new inspections will use.
     *
     * Does NOT deactivate the others — a factory has no business enforcing
     * that rule, and a test that wants two active templates to prove
     * ChecklistTemplate::current() picks the newest must be able to make them.
     * ChecklistService::activate() is the enforcing path.
     */
    public function active(): static
    {
        return $this->state(['is_active' => true]);
    }

    public function version(int $version): static
    {
        return $this->state(['version' => $version]);
    }
}
