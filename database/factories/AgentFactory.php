<?php

namespace Database\Factories;

use App\Models\Agent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Agents for tests. These carry no working token: use AgentService::issue()
 * for one that authenticates.
 *
 * @extends Factory<Agent>
 */
class AgentFactory extends Factory
{
    protected $model = Agent::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => 'Probe '.$this->faker->unique()->numberBetween(1, 9999),
        ];
    }
}
