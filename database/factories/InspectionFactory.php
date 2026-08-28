<?php

namespace Database\Factories;

use App\Enums\InspectionOutcome;
use App\Models\ChecklistTemplate;
use App\Models\Inspection;
use App\Models\LabPc;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Inspections for tests and for M7's risk-scoring input.
 *
 * Defaults to an inspection still in progress, because that is the state with
 * rules attached — who may edit it, whether it counts, whether it can be
 * abandoned. A completed one is the ->completed() state.
 *
 * @extends Factory<Inspection>
 */
class InspectionFactory extends Factory
{
    protected $model = Inspection::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'lab_pc_id' => LabPc::factory(),
            'user_id' => User::factory(),
            'checklist_template_id' => ChecklistTemplate::factory(),
            'started_at' => now()->subMinutes(10),
            'completed_at' => null,
            'overall_result' => null,
        ];
    }

    /**
     * A submitted inspection.
     *
     * The outcome is set directly here rather than derived, because a factory
     * that called InspectionService would need real items and answers to
     * build one row. Tests that care about the DERIVATION must go through
     * InspectionService::complete() — a factory asserting its own state
     * proves nothing.
     */
    public function completed(InspectionOutcome $outcome = InspectionOutcome::Pass): static
    {
        return $this->state([
            'overall_result' => $outcome,
            'completed_at' => now(),
        ]);
    }
}
