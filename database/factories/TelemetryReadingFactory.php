<?php

namespace Database\Factories;

use App\Enums\NetworkStatus;
use App\Models\AccessPoint;
use App\Models\TelemetryReading;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TelemetryReading>
 */
class TelemetryReadingFactory extends Factory
{
    protected $model = TelemetryReading::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'network_device_id' => AccessPoint::factory(),
            'recorded_at' => now(),
            'latency_ms' => $this->faker->numberBetween(8, 45),
            'client_count' => $this->faker->numberBetween(0, 20),
            'packet_loss_pct' => 0,
            'channel_util_pct' => $this->faker->numberBetween(5, 40),
            'throughput_mbps' => $this->faker->numberBetween(1, 80),
            'status' => NetworkStatus::Online->value,
            'source' => 'simulated',
        ];
    }

    /** Over the latency threshold. */
    public function slow(): static
    {
        return $this->state(['latency_ms' => 400]);
    }

    /** Over the packet loss threshold. */
    public function lossy(): static
    {
        return $this->state(['packet_loss_pct' => 22.5]);
    }

    public function offline(): static
    {
        return $this->state([
            'status' => NetworkStatus::Offline->value,
            'latency_ms' => null,
            'client_count' => 0,
            'packet_loss_pct' => 100,
        ]);
    }

    public function at(\DateTimeInterface $when): static
    {
        return $this->state(['recorded_at' => $when]);
    }
}
