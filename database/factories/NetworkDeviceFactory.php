<?php

namespace Database\Factories;

use App\Enums\DeviceKind;
use App\Enums\NetworkStatus;
use App\Models\Location;
use App\Models\NetworkDevice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Switches, routers and servers. Access points have AccessPointFactory, with
 * a capacity and a MAC address.
 *
 * @extends Factory<NetworkDevice>
 */
class NetworkDeviceFactory extends Factory
{
    protected $model = NetworkDevice::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $kind = $this->faker->randomElement([DeviceKind::Switch, DeviceKind::Router, DeviceKind::Server]);

        return [
            'kind' => $kind->value,
            'code' => strtoupper($this->faker->unique()->bothify(match ($kind) {
                DeviceKind::Switch => 'SW-??-##',
                DeviceKind::Router => 'RT-??-##',
                default => 'SV-??-##',
            })),
            'name' => $kind->label().' '.$this->faker->numberBetween(1, 9),
            'location_id' => Location::factory(),
            'model' => $this->faker->randomElement(['Cisco C9200-24P', 'Aruba 2930F', 'MikroTik RB5009']),
            'ip_address' => $this->faker->localIpv4(),
            'status' => NetworkStatus::Online->value,
        ];
    }

    public function kind(DeviceKind $kind): static
    {
        return $this->state(['kind' => $kind->value]);
    }

    /** @see LabPcFactory::demo() */
    public function demo(): static
    {
        return $this->afterCreating(fn (NetworkDevice $device) => $device->forceFill(['is_demo' => true])->save());
    }
}
