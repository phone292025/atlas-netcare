<?php

namespace App\Http\Requests\Telemetry;

use App\Enums\SimulationScenario;
use App\Models\AccessPoint;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A scenario for the Network monitor's simulation controls.
 *
 * `scenario` is a SimulationScenario value or `recovery`, which clears it.
 * `access_point_id` is one access point, or empty for every one being polled.
 *
 * Who may ask is the route's business (`role:admin,engineer`); whether the
 * installation can simulate at all is SimulationService's.
 */
class RunSimulationRequest extends FormRequest
{
    public const RECOVERY = 'recovery';

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'scenario' => ['required', Rule::in([...SimulationScenario::values(), self::RECOVERY])],
            'access_point_id' => ['nullable', 'integer', Rule::exists('access_points', 'id')->whereNull('deleted_at')],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'scenario.required' => 'Choose a scenario to run.',
            'scenario.in' => 'Choose one of the listed scenarios.',
            'access_point_id.exists' => 'That access point is not in the inventory any more.',
        ];
    }

    /** The scenario to apply, or null for Recovery. */
    public function scenario(): ?SimulationScenario
    {
        return SimulationScenario::tryFrom((string) $this->validated('scenario'));
    }

    /** The one access point targeted, or null for all of them. */
    public function target(): ?AccessPoint
    {
        $id = $this->validated('access_point_id');

        return $id === null ? null : AccessPoint::findOrFail($id);
    }
}
