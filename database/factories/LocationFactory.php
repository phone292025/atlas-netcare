<?php

namespace Database\Factories;

use App\Models\Location;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Locations are reference data, so the real ones are written out explicitly in
 * ReferenceSeeder. This factory exists for tests that need *a* location without
 * caring which — and for M2's demo data.
 *
 * @extends Factory<Location>
 */
class LocationFactory extends Factory
{
    protected $model = Location::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'code' => strtoupper($this->faker->unique()->bothify('L#-???-##')),
            'name' => 'Level '.$this->faker->numberBetween(1, 8).' '.$this->faker->randomElement(['IT Lab', 'Study Area', 'Networking Lab']),
            'type' => $this->faker->randomElement(['it_lab', 'library', 'cafeteria', 'other']),
            'floor' => 'Level '.$this->faker->numberBetween(1, 8),
            'description' => null,
        ];
    }
}
