<?php

namespace Database\Factories;

use App\Enums\DeviceStatus;
use App\Models\LabPc;
use App\Models\Location;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Lab workstations for tests and for M2's demo data.
 *
 * Purchase dates spread across six years on purpose: age is one of the four
 * factors in M7's risk score, so a demo dataset where every machine is the same
 * age would make the ranking screen look broken.
 *
 * @extends Factory<LabPc>
 */
class LabPcFactory extends Factory
{
    protected $model = LabPc::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'asset_tag' => strtoupper($this->faker->unique()->bothify('APU-####')),
            'location_id' => Location::factory(),
            'hostname' => strtolower($this->faker->unique()->bothify('apu-pc-###')),
            'cpu' => $this->faker->randomElement(['Intel i5-10500', 'Intel i7-11700', 'Ryzen 5 5600G']),
            'ram_gb' => $this->faker->randomElement([8, 16, 32]),
            'storage' => $this->faker->randomElement(['256GB SSD', '512GB SSD', '1TB HDD']),
            'os_version' => $this->faker->randomElement(['Windows 10 22H2', 'Windows 11 23H2']),
            'purchase_date' => $this->faker->dateTimeBetween('-6 years', '-3 months'),
            'warranty_expiry' => $this->faker->dateTimeBetween('-1 year', '+2 years'),
            'status' => DeviceStatus::Operational->value,
        ];
    }

    /**
     * Tagged so `atlas:purge-demo-data` can remove it and leave real records
     * alone. Every row DemoDataSeeder creates must use this.
     */
    public function demo(): static
    {
        return $this->afterCreating(fn (LabPc $pc) => $pc->forceFill(['is_demo' => true])->save());
    }

    public function retired(): static
    {
        return $this->state(['status' => DeviceStatus::Retired->value]);
    }
}
