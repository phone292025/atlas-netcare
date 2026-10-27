<?php

namespace Tests\Feature\Telemetry;

use App\Enums\SimulationScenario;
use App\Enums\UserRole;
use App\Models\AccessPoint;
use App\Models\Agent;
use App\Models\TelemetryHourly;
use App\Models\TelemetryReading;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * The first collection round after the scheduler has been off.
 *
 * The Network monitor charts the last day of raw readings. With the scheduler
 * stopped for a week, it came back to one point and an empty chart, and the
 * only way out was running `atlas:backfill-telemetry` by hand. On the
 * simulator's devices the gap is now filled by the round that ends it.
 *
 * @see App\Services\Telemetry\TelemetryBackfill::fillGap()
 */
class CollectionGapTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-09-16 12:00:00'));

        $admin = User::factory()->create();
        $admin->forceFill(['role' => UserRole::Admin, 'is_active' => true])->save();
    }

    /** @return Collection<int, AccessPoint> */
    private function accessPointsLastHeardFrom(Carbon $at, int $count = 3): Collection
    {
        return AccessPoint::factory()->count($count)->create()->each(function (AccessPoint $ap) use ($at) {
            TelemetryReading::factory()->create(['network_device_id' => $ap->id, 'recorded_at' => $at]);
            $ap->forceFill(['last_seen_at' => $at])->save();
        });
    }

    /** The longest silence between consecutive readings for this access point, in minutes. */
    private function longestSilence(AccessPoint $ap, Carbon $since): float
    {
        $times = TelemetryReading::where('network_device_id', $ap->id)
            ->where('recorded_at', '>=', $since)
            ->orderBy('recorded_at')
            ->pluck('recorded_at');

        return $times->sliding(2)->map(fn ($pair) => $pair->first()->diffInMinutes($pair->last()))->max() ?? 0;
    }

    public function test_the_first_round_after_a_gap_fills_it(): void
    {
        $aps = $this->accessPointsLastHeardFrom(now()->subHours(3));

        $this->artisan('atlas:collect-telemetry')
            ->expectsOutputToContain('simulated readings')
            ->assertExitCode(0);

        foreach ($aps as $ap) {
            $this->assertLessThanOrEqual(5, $this->longestSilence($ap, now()->subHours(3)), "{$ap->code} still has a hole.");
            $this->assertSame(now()->toDateTimeString(), TelemetryReading::where('network_device_id', $ap->id)->max('recorded_at'));
        }
    }

    public function test_the_filled_hours_reach_the_hourly_charts(): void
    {
        $aps = $this->accessPointsLastHeardFrom(now()->subHours(3));

        $this->artisan('atlas:collect-telemetry');

        foreach (['09:00', '10:00', '11:00'] as $hour) {
            $this->assertSame(
                $aps->count(),
                TelemetryHourly::where('hour_bucket', "2026-09-16 {$hour}:00")->count(),
                "No hourly bucket for {$hour}.",
            );
        }
    }

    public function test_it_fills_no_further_back_than_a_day(): void
    {
        // A week off. The longest chart is a day, and a week of invented
        // history is 60,000 rows nobody asked for.
        $aps = $this->accessPointsLastHeardFrom(now()->subWeek());

        $this->artisan('atlas:collect-telemetry');

        $dayAgo = now()->subHours((int) config('atlas.gap_fill_hours'));

        foreach ($aps as $ap) {
            $this->assertSame(
                0,
                TelemetryReading::where('network_device_id', $ap->id)
                    ->where('recorded_at', '>', now()->subWeek())
                    ->where('recorded_at', '<', $dayAgo)
                    ->count(),
                'It filled further back than a day.',
            );
            $this->assertLessThanOrEqual(5, $this->longestSilence($ap, $dayAgo));
        }
    }

    public function test_nothing_is_invented_when_nothing_was_ever_collected(): void
    {
        // No history means the collector never ran, not that it stopped. The
        // chart says "No readings in this range" itself, which is true.
        $aps = AccessPoint::factory()->count(3)->create();

        $this->artisan('atlas:collect-telemetry');

        foreach ($aps as $ap) {
            $this->assertSame(1, TelemetryReading::where('network_device_id', $ap->id)->count());
        }
    }

    public function test_a_short_pause_is_not_a_gap(): void
    {
        $aps = $this->accessPointsLastHeardFrom(now()->subMinutes(3));

        $this->artisan('atlas:collect-telemetry')->doesntExpectOutputToContain('simulated readings');

        foreach ($aps as $ap) {
            $this->assertSame(2, TelemetryReading::where('network_device_id', $ap->id)->count());
        }
    }

    public function test_an_interrupted_feed_is_not_filled_in(): void
    {
        /*
         * A simulated feed interruption means nobody is listening to that
         * access point, so it reads as unknown. Filling its gap would invent
         * readings for a device the collector deliberately cannot see. Only
         * what the driver polls is filled, the same set live collection uses.
         */
        [$interrupted, $healthy] = $this->accessPointsLastHeardFrom(now()->subHours(3), 2)->all();
        $interrupted->forceFill([
            'simulation_scenario' => SimulationScenario::FeedInterruption,
            'simulation_started_at' => now()->subHours(4),
        ])->save();

        $this->artisan('atlas:collect-telemetry');

        $this->assertSame(1, TelemetryReading::where('network_device_id', $interrupted->id)->count(), 'The interrupted feed was filled in.');
        $this->assertGreaterThan(30, TelemetryReading::where('network_device_id', $healthy->id)->count(), 'The healthy one was not filled.');
    }

    public function test_a_real_agents_devices_are_never_filled(): void
    {
        // Silence from real access points is a fact about the network.
        $agent = Agent::factory()->create();

        $this->accessPointsLastHeardFrom(now()->subHours(3))
            ->each(fn (AccessPoint $ap) => $ap->forceFill(['agent_id' => $agent->id])->save());

        $this->artisan('atlas:collect-telemetry');

        $this->assertSame(3, TelemetryReading::count());
    }
}
