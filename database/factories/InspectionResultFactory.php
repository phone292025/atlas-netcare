<?php

namespace Database\Factories;

use App\Enums\ChecklistResult;
use App\Models\ChecklistItem;
use App\Models\Inspection;
use App\Models\InspectionResult;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InspectionResult>
 */
class InspectionResultFactory extends Factory
{
    protected $model = InspectionResult::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'inspection_id' => Inspection::factory(),
            'checklist_item_id' => ChecklistItem::factory(),
            'result' => ChecklistResult::Ok->value,
            'remark' => null,
        ];
    }

    public function issue(?string $remark = 'Reported during a lab round.'): static
    {
        return $this->state([
            'result' => ChecklistResult::Issue->value,
            'remark' => $remark,
        ]);
    }

    public function notApplicable(): static
    {
        return $this->state(['result' => ChecklistResult::Na->value]);
    }
}
