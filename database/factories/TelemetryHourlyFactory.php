<?php

namespace Database\Factories;

use App\Models\AccessPoint;
use App\Models\TelemetryHourly;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TelemetryHourly>
 */
class TelemetryHourlyFactory extends Factory
{
    protected $model = TelemetryHourly::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'access_point_id' => AccessPoint::factory(),
            'hour_bucket' => now()->startOfHour(),
            'avg_latency_ms' => $this->faker->numberBetween(10, 60),
            'peak_client_count' => $this->faker->numberBetween(5, 45),
            'avg_packet_loss_pct' => 0.4,
            'avg_channel_util_pct' => 35,
            'uptime_pct' => 100,
            'reading_count' => 60,
            'source' => 'simulated',
        ];
    }
}
