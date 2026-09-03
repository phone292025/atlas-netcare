<?php

namespace Tests\Feature\Telemetry;

use App\Enums\ApStatus;
use App\Enums\UserRole;
use App\Models\AccessPoint;
use App\Models\TelemetryHourly;
use App\Models\TelemetryReading;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Telemetry\SimulatedTelemetrySource;
use App\Services\Telemetry\SnmpTelemetrySource;
use App\Services\Telemetry\TelemetrySource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;
use Tests\TestCase;

/**
 * M5 — the driver swap, the commands, and the screens.
 *
 * @see docs/decisions/ADR-0002-telemetry-source-driver.md
 * @see docs/modules/M5-telemetry-and-dashboard.md
 */
class TelemetryPipelineTest extends TestCase
{
    use RefreshDatabase;

    private function userWith(UserRole $role): User
    {
        $user = User::factory()->create();
        $user->forceFill(['role' => $role, 'is_active' => true])->save();

        return $user->fresh();
    }

    // ------------------------------------------------------- the driver swap

    public function test_the_driver_comes_from_config(): void
    {
        // ADR-0002's whole promise: going live is an .env change and one class.
        config(['atlas.telemetry_driver' => 'simulated']);
        $this->assertInstanceOf(SimulatedTelemetrySource::class, app(TelemetrySource::class));

        config(['atlas.telemetry_driver' => 'snmp']);
        $this->assertInstanceOf(SnmpTelemetrySource::class, app(TelemetrySource::class));
    }

    public function test_an_unknown_driver_throws_rather_than_falling_back(): void
    {
        /*
         * A typo in .env silently producing invented data on a system meant to
         * be showing real readings is the worst outcome available here, so it
         * fails loudly instead.
         */
        config(['atlas.telemetry_driver' => 'snmpp']);

        $this->expectException(\InvalidArgumentException::class);

        app(TelemetrySource::class);
    }

    public function test_the_snmp_driver_is_an_honest_stub(): void
    {
        // It throws rather than returning nothing: forty access points all
        // going quiet at once would cost somebody an afternoon.
        $this->expectException(RuntimeException::class);

        (new SnmpTelemetrySource)->collect();
    }

    // ------------------------------------------------------- the simulator

    public function test_the_simulator_reads_every_collectable_access_point(): void
    {
        AccessPoint::factory()->count(3)->create();
        AccessPoint::factory()->maintenance()->create();

        $readings = (new SimulatedTelemetrySource)->collect();

        // Maintenance is skipped — an engineer holding a ladder should not be
        // paged about the access point in their hands.
        $this->assertCount(3, $readings);
    }

    public function test_an_access_point_has_a_stable_personality(): void
    {
        /*
         * The same AP has to be consistently busier or consistently worse, run
         * after run. Without it "which access point is the problem?" has no
         * stable answer and M7's risk ranking means nothing.
         */
        $busy = AccessPoint::factory()->create(['client_capacity' => 60]);
        $source = new SimulatedTelemetrySource;

        $at = now()->setTime(11, 0);

        $first = collect(range(1, 12))->map(fn () => $source->reading($busy, $at)->clientCount)->avg();
        $second = collect(range(1, 12))->map(fn () => $source->reading($busy, $at)->clientCount)->avg();

        // Noise moves it a little; personality keeps it in the same place.
        $this->assertEqualsWithDelta($first, $second, max(4, $first * 0.25));
    }

    public function test_the_simulator_is_busier_at_midday_than_at_night(): void
    {
        // A flat 24-hour chart is the giveaway that the data is invented
        // carelessly. This is the daily curve actually being there.
        $ap = AccessPoint::factory()->create(['client_capacity' => 60]);
        $source = new SimulatedTelemetrySource;

        $noon = now()->next('Tuesday')->setTime(11, 0);
        $night = now()->next('Tuesday')->setTime(3, 0);

        $this->assertGreaterThan(
            $source->reading($ap, $night)->clientCount,
            $source->reading($ap, $noon)->clientCount,
        );
    }

    public function test_latency_rises_with_saturation(): void
    {
        /*
         * ADR-0002 is explicit: if latency is noise uncorrelated with client
         * count, M7 has nothing real to detect and a sharp marker will notice.
         * This is that correlation, asserted.
         *
         * ONE ACCESS POINT AT TWO HOURS, not two access points. The earlier
         * version compared a capacity-500 AP against a capacity-10 one and
         * assumed the small one would be saturated. It is not: `clientCount()`
         * is `capacity x popularity x curve`, so saturation does not depend on
         * capacity at all and the two APs differed only in the `popularity`
         * that `personality()` derives from their ID. The test was a coin toss
         * on two md5 hashes, and it landed differently on MariaDB than on
         * SQLite because in-memory SQLite hands out IDs 1 and 2 every time
         * while a real auto-increment keeps counting. Found 2026-09-04 by
         * running the suite against MariaDB for the first time.
         *
         * The same AP has one fixed personality, so the only thing changing
         * here is the time of day, which is exactly the variable the claim is
         * about.
         */
        $ap = AccessPoint::factory()->create(['client_capacity' => 60]);
        $source = new SimulatedTelemetrySource;
        $tuesday = now()->next('Tuesday');

        // Spread across the hour: outages are sticky for a 20-minute window,
        // so sampling one instant fifteen times would lose all fifteen.
        $meanLatencyAt = fn (int $hour) => collect(range(0, 14))
            ->map(fn (int $i) => $source->reading($ap, $tuesday->copy()->setTime($hour, $i * 4))->latencyMs)
            ->filter()
            ->avg();

        $overnight = $meanLatencyAt(3);
        $lateMorning = $meanLatencyAt(11);

        $this->assertNotNull($overnight, 'Every overnight reading was an outage.');
        $this->assertNotNull($lateMorning, 'Every late-morning reading was an outage.');

        $this->assertGreaterThan(
            $overnight,
            $lateMorning,
            'A busy access point must be slower than the same one at 3am.',
        );
    }

    // ------------------------------------------------------- the collector

    public function test_the_collector_writes_one_reading_per_access_point(): void
    {
        AccessPoint::factory()->count(4)->create();

        Artisan::call('atlas:collect-telemetry');

        $this->assertSame(4, TelemetryReading::count());
    }

    public function test_the_collector_updates_the_access_points_own_status(): void
    {
        // The denormalised cache the dashboard and inventory read, written in
        // the same transaction as the reading so the two cannot disagree.
        $ap = AccessPoint::factory()->create();

        Artisan::call('atlas:collect-telemetry');

        $this->assertNotNull($ap->fresh()->last_seen_at);
    }

    public function test_an_access_point_going_offline_raises_exactly_one_ticket(): void
    {
        /*
         * FR-503. Two guards are needed and this proves both: the ticket is
         * raised on the TRANSITION into offline rather than on every offline
         * reading, and hasOutstandingFor() stops a second one while the first
         * is still open. Without them a six-hour outage files 360 tickets.
         */
        $this->userWith(UserRole::Admin);
        $ap = AccessPoint::factory()->create([
            'status' => ApStatus::Online->value,
            'last_seen_at' => now()->subHours(3),
        ]);

        Artisan::call('atlas:collect-telemetry');
        $this->assertSame(ApStatus::Offline, $ap->fresh()->status);
        $this->assertSame(1, Ticket::count());

        // Still offline on the next round, and still one ticket.
        Artisan::call('atlas:collect-telemetry');
        $this->assertSame(1, Ticket::count());
    }

    public function test_no_ticket_is_raised_without_an_administrator_to_attribute_it_to(): void
    {
        // reported_by is not nullable, and the reading itself must still be
        // stored — losing telemetry because nobody can own a ticket would be
        // the wrong trade.
        AccessPoint::factory()->create([
            'status' => ApStatus::Online->value,
            'last_seen_at' => now()->subHours(3),
        ]);

        Artisan::call('atlas:collect-telemetry');

        $this->assertSame(0, Ticket::count());
        $this->assertSame(1, TelemetryReading::count());
    }

    // ------------------------------------------------------- aggregation

    public function test_aggregation_rolls_readings_into_hourly_buckets(): void
    {
        $ap = AccessPoint::factory()->create();
        $hour = now()->subHour()->startOfHour();

        foreach ([5, 25, 45] as $index => $minute) {
            TelemetryReading::factory()->create([
                'access_point_id' => $ap->id,
                'recorded_at' => $hour->copy()->addMinutes($minute),
                'client_count' => [10, 30, 20][$index],
                'latency_ms' => [20, 40, 30][$index],
            ]);
        }

        Artisan::call('atlas:aggregate-telemetry', ['--hours' => 3]);

        $bucket = TelemetryHourly::firstWhere('access_point_id', $ap->id);

        $this->assertNotNull($bucket);
        $this->assertSame(3, $bucket->reading_count);
        // Peak, not mean — a room that filled up for ten minutes is the fact
        // worth keeping.
        $this->assertSame(30, $bucket->peak_client_count);
        $this->assertEqualsWithDelta(30.0, (float) $bucket->avg_latency_ms, 0.01);
    }

    public function test_aggregation_is_idempotent(): void
    {
        /*
         * It is scheduled, and it can be run by hand or overlap itself. Without
         * the upsert against the unique index, a second pass doubles every
         * point on the chart.
         */
        $ap = AccessPoint::factory()->create();
        TelemetryReading::factory()->count(4)->create([
            'access_point_id' => $ap->id,
            'recorded_at' => now()->subHour()->startOfHour()->addMinutes(10),
        ]);

        Artisan::call('atlas:aggregate-telemetry', ['--hours' => 3]);
        Artisan::call('atlas:aggregate-telemetry', ['--hours' => 3]);

        $this->assertSame(1, TelemetryHourly::count());
        $this->assertSame(4, TelemetryHourly::first()->reading_count);
    }

    public function test_offline_readings_do_not_drag_the_latency_average_down(): void
    {
        /*
         * An unreachable access point has no latency. Counting it as zero would
         * make an outage read as the best hour that AP ever had — precisely
         * backwards, and the kind of thing nobody notices until the report.
         */
        $ap = AccessPoint::factory()->create();
        $hour = now()->subHour()->startOfHour();

        TelemetryReading::factory()->create([
            'access_point_id' => $ap->id,
            'recorded_at' => $hour->copy()->addMinutes(5),
            'latency_ms' => 40,
        ]);
        TelemetryReading::factory()->offline()->create([
            'access_point_id' => $ap->id,
            'recorded_at' => $hour->copy()->addMinutes(35),
        ]);

        Artisan::call('atlas:aggregate-telemetry', ['--hours' => 3]);

        $bucket = TelemetryHourly::firstWhere('access_point_id', $ap->id);

        $this->assertEqualsWithDelta(40.0, (float) $bucket->avg_latency_ms, 0.01);
        $this->assertEqualsWithDelta(50.0, (float) $bucket->uptime_pct, 0.01);
    }

    // ------------------------------------------------------------ pruning

    public function test_pruning_removes_only_readings_past_retention(): void
    {
        $ap = AccessPoint::factory()->create();

        TelemetryReading::factory()->create([
            'access_point_id' => $ap->id,
            'recorded_at' => now()->subDays(200),
        ]);
        TelemetryReading::factory()->create([
            'access_point_id' => $ap->id,
            'recorded_at' => now()->subDay(),
        ]);

        Artisan::call('atlas:prune-telemetry');

        $this->assertSame(1, TelemetryReading::count());
    }

    public function test_a_dry_run_deletes_nothing(): void
    {
        AccessPoint::factory()->create();
        TelemetryReading::factory()->create(['recorded_at' => now()->subDays(200)]);

        Artisan::call('atlas:prune-telemetry', ['--dry-run' => true]);

        $this->assertSame(1, TelemetryReading::count());
    }

    // ------------------------------------------------------- the screens

    public function test_the_dashboard_renders_when_there_is_nothing_yet(): void
    {
        // Empty is what every screen looks like on the day it ships.
        $this->actingAs($this->userWith(UserRole::Engineer))
            ->get(route('engineer.dashboard'))
            ->assertOk();
    }

    public function test_the_dashboard_renders_with_access_points(): void
    {
        AccessPoint::factory()->count(3)->create();
        Artisan::call('atlas:collect-telemetry');

        $this->actingAs($this->userWith(UserRole::Engineer))
            ->get(route('engineer.dashboard'))
            ->assertOk()
            ->assertSee('Access point health');
    }

    public function test_the_polling_endpoint_returns_the_same_shape_as_the_page(): void
    {
        AccessPoint::factory()->count(2)->create();
        Artisan::call('atlas:collect-telemetry');

        $this->actingAs($this->userWith(UserRole::Engineer))
            ->getJson(route('api.dashboard'))
            ->assertOk()
            ->assertJsonStructure([
                'generated_at',
                'counts' => ['total', 'online', 'degraded', 'offline', 'maintenance'],
                'clients_now',
                'access_points' => [['id', 'code', 'status', 'tone', 'clients', 'capacity']],
            ]);
    }

    public function test_the_polling_endpoint_requires_signing_in(): void
    {
        // The access point layout of a campus network is not public.
        $this->getJson(route('api.dashboard'))->assertUnauthorized();
    }

    public function test_the_access_point_detail_renders_with_history(): void
    {
        $ap = AccessPoint::factory()->create();
        $this->hoursFor($ap, 5);

        $this->actingAs($this->userWith(UserRole::Engineer))
            ->get(route('engineer.aps.show', $ap))
            ->assertOk()
            ->assertSee($ap->ap_code);
    }

    /**
     * Consecutive hourly buckets for one access point.
     *
     * One row per hour, because (access_point_id, hour_bucket) is unique —
     * that index is what stops a re-run of the aggregator doubling every point
     * on the chart, so test data has to respect it too.
     */
    private function hoursFor(AccessPoint $ap, int $count): void
    {
        for ($i = 1; $i <= $count; $i++) {
            TelemetryHourly::factory()->create([
                'access_point_id' => $ap->id,
                'hour_bucket' => now()->subHours($i)->startOfHour(),
            ]);
        }
    }

    public function test_the_traffic_log_renders_when_empty(): void
    {
        $this->actingAs($this->userWith(UserRole::Engineer))
            ->get(route('engineer.traffic.index'))
            ->assertOk();
    }

    public function test_the_traffic_log_renders_with_data(): void
    {
        $ap = AccessPoint::factory()->create();
        $this->hoursFor($ap, 3);

        $this->actingAs($this->userWith(UserRole::Engineer))
            ->get(route('engineer.traffic.index'))
            ->assertOk()
            ->assertSee($ap->ap_code);
    }

    public function test_an_assistant_sees_a_read_only_status_board(): void
    {
        AccessPoint::factory()->count(2)->create();

        $this->actingAs($this->userWith(UserRole::Assistant))
            ->get(route('assistant.aps.index'))
            ->assertOk()
            ->assertSee('Access point status');
    }

    public function test_an_assistant_cannot_reach_the_engineer_dashboard(): void
    {
        $this->actingAs($this->userWith(UserRole::Assistant))
            ->get(route('engineer.dashboard'))
            ->assertForbidden();
    }
}
