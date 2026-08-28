<?php

namespace Database\Factories;

use App\Enums\ApStatus;
use App\Models\AccessPoint;
use App\Models\Location;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Access points for tests and for M5's demo data.
 *
 * @extends Factory<AccessPoint>
 */
class AccessPointFactory extends Factory
{
    protected $model = AccessPoint::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'ap_code' => strtoupper($this->faker->unique()->bothify('AP-??-##')),
            'location_id' => Location::factory(),
            'model' => $this->faker->randomElement(['Cisco C9120AXI', 'Aruba AP-515', 'Ubiquiti U6-Pro']),
            'mac_address' => $this->faker->unique()->macAddress(),
            'ip_address' => $this->faker->localIpv4(),
            'client_capacity' => $this->faker->randomElement([30, 50, 80]),
            'installed_at' => $this->faker->dateTimeBetween('-5 years', '-1 month'),
            'status' => ApStatus::Online->value,
        ];
    }

    /** @see LabPcFactory::demo() */
    public function demo(): static
    {
        return $this->afterCreating(fn (AccessPoint $ap) => $ap->forceFill(['is_demo' => true])->save());
    }

    public function maintenance(): static
    {
        return $this->state(['status' => ApStatus::Maintenance->value]);
    }
}
