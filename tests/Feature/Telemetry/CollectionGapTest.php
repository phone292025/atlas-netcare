<?php

namespace Tests\Feature\Telemetry;

use App\Enums\UserRole;
use App\Models\AccessPoint;
use App\Models\TelemetryHourly;
use App\Models\TelemetryReading;
use App\Models\User;
use App\Services\Telemetry\TelemetrySource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * The first collection round after the scheduler has been off.
 *
 * The Network monitor charts the last day of raw readings. With the scheduler
 * stopped for a week, it came back to one point and an empty chart, and the
 * only way out was running `atlas:backfill-telemetry` by hand. With the
 * simulated driver the gap is now filled by the round that ends it.
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
            TelemetryReading::factory()->create(['access_point_id' => $ap->id, 'recorded_at' => $at]);
            $ap->forceFill(['last_seen_at' => $at])->save();
        });
    }

    /** The longest silence between consecutive readings for this access point, in minutes. */
    private function longestSilence(AccessPoint $ap, Carbon $since): float
    {
        $times = TelemetryReading::where('access_point_id', $ap->id)
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
            $this->assertLessThanOrEqual(5, $this->longestSilence($ap, now()->subHours(3)), "{$ap->ap_code} still has a hole.");
            $this->assertSame(now()->toDateTimeString(), TelemetryReading::where('access_point_id', $ap->id)->max('recorded_at'));
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
                TelemetryReading::where('access_point_id', $ap->id)
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
            $this->assertSame(1, TelemetryReading::where('access_point_id', $ap->id)->count());
        }
    }

    public function test_a_short_pause_is_not_a_gap(): void
    {
        $aps = $this->accessPointsLastHeardFrom(now()->subMinutes(3));

        $this->artisan('atlas:collect-telemetry')->doesntExpectOutputToContain('simulated readings');

        foreach ($aps as $ap) {
            $this->assertSame(2, TelemetryReading::where('access_point_id', $ap->id)->count());
        }
    }

    public function test_an_hour_written_by_the_seeder_is_never_written_into(): void
    {
        /*
         * A bucket with no readings under it was written directly, by
         * DemoDataSeeder. Readings added to its hour would let the next
         * aggregation replace it, which is how the three degrading access
         * points lost their trend to a backfill.
         */
        $aps = AccessPoint::factory()->count(2)->create()->each(function (AccessPoint $ap) {
            TelemetryHourly::factory()->create([
                'access_point_id' => $ap->id,
                'hour_bucket' => '2026-09-16 09:00:00',
                'avg_latency_ms' => 999,
                'reading_count' => 60,
                'source' => 'simulated',
            ]);
            $ap->forceFill(['last_seen_at' => '2026-09-16 09:59:00'])->save();
        });

        $this->artisan('atlas:collect-telemetry');

        $this->assertSame(0, TelemetryReading::where('recorded_at', '<', '2026-09-16 10:00:00')->count(), 'Readings were written into the seeded hour.');
        $this->assertSame($aps->count(), TelemetryHourly::where('hour_bucket', '2026-09-16 09:00:00')->where('avg_latency_ms', 999)->count(), 'The seeded bucket was rewritten.');
        $this->assertSame($aps->count(), TelemetryHourly::where('hour_bucket', '2026-09-16 10:00:00')->count(), 'The gap after it was not filled.');
    }

    public function test_a_real_driver_never_fills_a_gap(): void
    {
        // Silence from real access points is a fact about the network.
        $this->app->instance(TelemetrySource::class, new class implements TelemetrySource
        {
            public function sourceLabel(): string
            {
                return 'live';
            }

            /** @return Builder<AccessPoint> */
            public function pollable(): Builder
            {
                return AccessPoint::query()->collectable();
            }

            public function collect(): Collection
            {
                return collect();
            }
        });

        $this->accessPointsLastHeardFrom(now()->subHours(3));

        $this->artisan('atlas:collect-telemetry');

        $this->assertSame(3, TelemetryReading::count());
    }
}
