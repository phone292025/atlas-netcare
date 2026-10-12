<?php

namespace Tests\Feature\Telemetry;

use App\Enums\AlarmType;
use App\Enums\ApStatus;
use App\Enums\UserRole;
use App\Models\AccessPoint;
use App\Models\Alarm;
use App\Models\TelemetryHourly;
use App\Models\TelemetryReading;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Telemetry\ReadingData;
use App\Services\Telemetry\SimulatedTelemetrySource;
use App\Services\Telemetry\SnmpTelemetrySource;
use App\Services\Telemetry\TelemetryService;
use App\Services\Telemetry\TelemetrySource;
use App\Support\CampusClock;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
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

    /**
     * A driver bound in place of the simulator, reporting whatever `$reading`
     * makes for each access point, so a test can force a state without betting
     * on the simulator's randomness.
     *
     * @param  Closure(AccessPoint): ReadingData  $reading
     */
    private function driverReporting(Closure $reading): void
    {
        $this->app->instance(TelemetrySource::class, new class($reading) implements TelemetrySource
        {
            public function __construct(private readonly Closure $reading) {}

            public function sourceLabel(): string
            {
                return 'simulated';
            }

            /** @return Builder<AccessPoint> */
            public function pollable(): Builder
            {
                return AccessPoint::query()->collectable();
            }

            /** @return Collection<int, ReadingData> */
            public function collect(): Collection
            {
                return $this->pollable()->get()->map(fn (AccessPoint $ap) => ($this->reading)($ap));
            }
        });
    }

    /**
     * Every access point fails to answer. Forces "offline" without relying on
     * a stale `last_seen_at`, which is exactly the thing classify() must ignore.
     */
    private function driverWhereNothingAnswers(): void
    {
        $this->driverReporting(fn (AccessPoint $ap) => ReadingData::unreachable($ap, now(), 'simulated'));
    }

    /** Every access point answers, with latency far over the threshold. */
    private function driverWhereEverythingIsSlow(): void
    {
        $this->driverReporting(fn (AccessPoint $ap) => new ReadingData(
            accessPointId: $ap->id,
            recordedAt: now(),
            latencyMs: 500,
            clientCount: 5,
            packetLossPct: 0.0,
            channelUtilPct: 10.0,
            throughputMbps: 20.0,
            source: 'simulated',
        ));
    }

    /** Every access point answers well inside every threshold. */
    private function driverWhereEverythingIsHealthy(): void
    {
        $this->driverReporting(fn (AccessPoint $ap) => new ReadingData(
            accessPointId: $ap->id,
            recordedAt: now(),
            latencyMs: 20,
            clientCount: 5,
            packetLossPct: 0.0,
            channelUtilPct: 10.0,
            throughputMbps: 20.0,
            source: 'simulated',
        ));
    }

    private function collectRounds(int $rounds): void
    {
        foreach (range(1, $rounds) as $round) {
            $this->travel(1)->minutes();
            Artisan::call('atlas:collect-telemetry');
        }
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

    // ------------------------------------------------- the stale sweep

    public function test_the_stale_sweep_only_judges_access_points_the_driver_actually_polls(): void
    {
        /*
         * THE BUG THIS PINS. `markStaleOffline()` marks a device offline when
         * it has gone quiet, and it used to ask for every access point the
         * system watches. Silence is only evidence of failure if somebody was
         * listening: one no driver pings is not quiet, it is unasked, and
         * marking it offline raises a ticket about hardware nothing tried to
         * reach.
         *
         * Both shipped drivers poll everything, so the bug is invisible until
         * one polls a subset. The driver below is that case, written inline
         * rather than borrowed from a real one, so the guard survives whatever
         * drivers happen to exist. Measured against a partial driver on the
         * development database: 28 access points marked offline, 28 tickets
         * and 56 alarms, on the first tick of the scheduler.
         *
         * Both access points below have been silent for a week. Only one is
         * something this driver ever tries to reach.
         */
        $this->app->instance(TelemetrySource::class, new class implements TelemetrySource
        {
            public function sourceLabel(): string
            {
                return 'simulated';
            }

            /** @return Builder<AccessPoint> */
            public function pollable(): Builder
            {
                return AccessPoint::query()->collectable()->where('is_demo', false);
            }

            /** @return Collection<int, ReadingData> */
            public function collect(): Collection
            {
                return collect();
            }
        });

        $unpolled = AccessPoint::factory()->create([
            'is_demo' => true,
            'status' => ApStatus::Online,
            'last_seen_at' => now()->subWeek(),
        ]);

        $polled = AccessPoint::factory()->create([
            'is_demo' => false,
            'status' => ApStatus::Online,
            'last_seen_at' => now()->subWeek(),
        ]);

        $transitions = app(TelemetryService::class)->markStaleOffline();

        $this->assertSame(
            ApStatus::Online,
            $unpolled->fresh()->status,
            'An access point nothing polls was marked offline for staying quiet.',
        );

        $this->assertSame(ApStatus::Offline, $polled->fresh()->status);
        $this->assertCount(1, $transitions);
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

        /*
         * Midday and 3 am on the CAMPUS clock, handed over as UTC moments —
         * which is what the collector passes in production, since the
         * application runs in UTC. This used to build the times in UTC, so it
         * checked 11:00 UTC against 03:00 UTC: 7 pm against 11 am on campus.
         * It passed while the simulator put the busiest hours in the evening.
         */
        $tuesday = CampusClock::now()->next('Tuesday');

        /*
         * Sampled across the hour, skipping outages. A single reading at one
         * instant is a bet that the instant is not inside an outage window,
         * and an unreachable reading reports ZERO clients — so an outage at
         * 11:00 makes midday look quieter than 03:00 and the assertion
         * inverts. Outage windows are 20 minutes and seeded from the row ID,
         * which is why that bet lands differently on MariaDB than on SQLite.
         */
        $meanClientsAt = fn (int $hour) => collect(range(0, 11))
            ->map(fn (int $i) => $source->reading($ap, $tuesday->copy()->setTime($hour, $i * 5)->utc()))
            ->reject(fn ($reading) => $reading->unreachable)
            ->avg(fn ($reading) => $reading->clientCount);

        $night = $meanClientsAt(3);
        $noon = $meanClientsAt(11);

        $this->assertNotNull($night, 'Every overnight reading was an outage.');
        $this->assertNotNull($noon, 'Every midday reading was an outage.');

        $this->assertGreaterThan($night, $noon);
    }

    public function test_latency_rises_with_saturation(): void
    {
        /*
         * ADR-0002: if latency were noise uncorrelated with client count, M7
         * would have nothing real to detect. This is that correlation,
         * asserted.
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
        // Campus hours, handed over as UTC moments — see
        // test_the_simulator_is_busier_at_midday_than_at_night.
        $tuesday = CampusClock::now()->next('Tuesday');

        // Spread across the hour: outages are sticky for a 20-minute window,
        // so sampling one instant fifteen times would lose all fifteen.
        $meanLatencyAt = fn (int $hour) => collect(range(0, 14))
            ->map(fn (int $i) => $source->reading($ap, $tuesday->copy()->setTime($hour, $i * 4)->utc())->latencyMs)
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
        /*
         * The denormalised cache the dashboard and inventory read, written in
         * the same transaction as the reading so the two cannot disagree.
         *
         * ASSERTS THE PAIRING, not that one access point answered. The earlier
         * version created a single AP and required `last_seen_at` to be set,
         * which is a bet that the AP is not in an outage — and `isOutage()` is
         * seeded from the row ID, so the bet lands differently on MariaDB
         * (where auto-increment keeps climbing) than on in-memory SQLite. It
         * passed alone and failed in a full MariaDB run, which reads like a
         * driver bug and is not one.
         *
         * The real contract is narrower and does not depend on luck: an access
         * point that ANSWERED gets a timestamp, and one that did not keeps the
         * one it had. Stamping a failed poll would mean nothing is ever
         * offline.
         */
        AccessPoint::factory()->count(6)->create();

        Artisan::call('atlas:collect-telemetry');

        $readings = TelemetryReading::all()->keyBy('access_point_id');

        $this->assertCount(6, $readings, 'Every access point should have been polled.');

        $answered = 0;

        foreach (AccessPoint::all() as $ap) {
            // packet_loss_pct 100 with no latency is what unreachable() writes.
            $reading = $readings[$ap->id];
            $unreachable = $reading->latency_ms === null;

            if ($unreachable) {
                $this->assertNull(
                    $ap->last_seen_at,
                    "{$ap->ap_code} did not answer but was stamped as seen.",
                );

                continue;
            }

            $answered++;

            $this->assertNotNull(
                $ap->last_seen_at,
                "{$ap->ap_code} answered but was not stamped as seen.",
            );
        }

        // Outages run at a few percent, so six all failing at once would mean
        // the simulator is broken rather than unlucky.
        $this->assertGreaterThan(0, $answered, 'Every access point was unreachable at once.');
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
        $ap = AccessPoint::factory()->create(['status' => ApStatus::Online->value]);
        $this->driverWhereNothingAnswers();

        Artisan::call('atlas:collect-telemetry');
        $this->assertSame(ApStatus::Offline, $ap->fresh()->status);
        $this->assertSame(1, Ticket::count());

        // Still offline on the next round, and still one ticket.
        Artisan::call('atlas:collect-telemetry');
        $this->assertSame(1, Ticket::count());
    }

    public function test_an_access_point_turning_degraded_alerts_the_engineers_once(): void
    {
        // FR-305: anomalies, not only outages. One alert on the transition,
        // and no ticket: congestion is a warning, not a fault report.
        $this->userWith(UserRole::Engineer);
        $ap = AccessPoint::factory()->create(['status' => ApStatus::Online->value]);
        $this->driverWhereEverythingIsSlow();

        $this->collectRounds((int) config('atlas.thresholds.consecutive_breach') + 1);

        $this->assertSame(ApStatus::Degraded, $ap->fresh()->status);
        $this->assertSame(1, Alarm::where('type', AlarmType::ApDegraded)->count());
        $this->assertSame(0, Ticket::count());
    }

    public function test_a_flapping_access_point_alerts_once_per_cooldown(): void
    {
        // At peak an access point near a threshold goes Degraded, back to
        // Online on one good reading, and Degraded again minutes later. One
        // alert per flap sent each engineer nineteen in five minutes.
        $this->userWith(UserRole::Engineer);
        AccessPoint::factory()->create(['status' => ApStatus::Online->value]);
        $breaches = (int) config('atlas.thresholds.consecutive_breach');
        $degradedAlerts = fn () => Alarm::where('type', AlarmType::ApDegraded)->count();

        $flap = function () use ($breaches): void {
            $this->driverWhereEverythingIsSlow();
            $this->collectRounds($breaches);
            $this->driverWhereEverythingIsHealthy();
            $this->collectRounds(1);
        };

        $flap();
        $flap();
        $this->assertSame(1, $degradedAlerts(), 'A second flap inside the cooldown alerted again.');

        $this->travel((int) config('atlas.thresholds.degraded_alert_cooldown_min') + 1)->minutes();
        $flap();
        $this->assertSame(2, $degradedAlerts(), 'A flap after the cooldown did not alert.');
    }

    public function test_recovering_from_an_outage_raises_no_degraded_alert(): void
    {
        // The unreachable readings count as 100% packet loss, so an access
        // point coming back passes through Degraded. That is recovery.
        $this->userWith(UserRole::Engineer);
        $ap = AccessPoint::factory()->create(['status' => ApStatus::Online->value]);

        $this->driverWhereNothingAnswers();
        $this->collectRounds((int) config('atlas.thresholds.consecutive_breach') - 1);
        $this->assertSame(ApStatus::Offline, $ap->fresh()->status);

        $this->driverWhereEverythingIsSlow();
        $this->collectRounds(1);

        $this->assertSame(ApStatus::Degraded, $ap->fresh()->status);
        $this->assertSame(0, Alarm::where('type', AlarmType::ApDegraded)->count());
    }

    public function test_no_ticket_is_raised_without_an_administrator_to_attribute_it_to(): void
    {
        // reported_by is not nullable, and the reading itself must still be
        // stored — losing telemetry because nobody can own a ticket would be
        // the wrong trade.
        AccessPoint::factory()->create(['status' => ApStatus::Online->value]);
        $this->driverWhereNothingAnswers();

        Artisan::call('atlas:collect-telemetry');

        $this->assertSame(0, Ticket::count());
        $this->assertSame(1, TelemetryReading::count());
    }

    public function test_the_first_run_after_a_collection_gap_raises_no_tickets(): void
    {
        /*
         * REGRESSION, and the one that would have been seen on demo day. With
         * the collector stopped for two days — a laptop asleep, a scheduler
         * nobody started — every access point's `last_seen_at` is stale. The
         * first run afterwards used to mark all of them offline on readings
         * that proved they were up, raise one ticket per access point to the
         * administrator, and flip them all back online a minute later.
         *
         * Uses the real simulator, so an access point in one of its outages
         * may legitimately go offline. The contract is therefore per access
         * point: one that ANSWERED is online and has no ticket.
         */
        $this->userWith(UserRole::Admin);
        AccessPoint::factory()->count(6)->create([
            'status' => ApStatus::Online->value,
            'last_seen_at' => now()->subDays(2),
        ]);

        Artisan::call('atlas:collect-telemetry');

        $readings = TelemetryReading::all()->keyBy('access_point_id');
        $answered = 0;

        foreach (AccessPoint::all() as $ap) {
            if ($readings[$ap->id]->latency_ms === null) {
                continue; // an outage in the simulator, not the bug
            }

            $answered++;
            $this->assertSame(ApStatus::Online, $ap->status, "{$ap->ap_code} answered but was marked offline.");
            $this->assertSame(0, Ticket::where('ticketable_id', $ap->id)->count(), "{$ap->ap_code} answered but got a ticket.");
        }

        $this->assertGreaterThan(0, $answered, 'Every access point was unreachable at once.');
    }

    public function test_an_automatic_ticket_about_a_demo_access_point_is_demo_data(): void
    {
        /*
         * Otherwise the collector, running in demo mode, writes real tickets
         * that point at demo assets, and `atlas:purge-demo-data` refuses to
         * run after a single round because deleting the assets would orphan
         * them. A ticket the SYSTEM raised about demonstration kit is
         * demonstration data; one a person raised is not, whatever the asset.
         */
        $this->userWith(UserRole::Admin);
        $demo = AccessPoint::factory()->create(['status' => ApStatus::Online->value, 'ap_code' => 'AP-DEMO']);
        $real = AccessPoint::factory()->create(['status' => ApStatus::Online->value, 'ap_code' => 'AP-REAL']);
        // Set explicitly on both: is_demo is not fillable, and the factory's
        // default is deliberately not what this test is about.
        $demo->forceFill(['is_demo' => true])->save();
        $real->forceFill(['is_demo' => false])->save();
        $this->driverWhereNothingAnswers();

        Artisan::call('atlas:collect-telemetry');

        $this->assertTrue(Ticket::where('ticketable_id', $demo->id)->firstOrFail()->is_demo);
        $this->assertFalse(Ticket::where('ticketable_id', $real->id)->firstOrFail()->is_demo);
    }

    public function test_the_polling_endpoint_says_how_old_the_readings_are(): void
    {
        /*
         * `generated_at` is when the payload was built, which is always "just
         * now". The screens used to show only that, so a dashboard on a
         * machine whose collector had stopped two days earlier said "updated
         * 1s ago" over 29 green rows. `readings_as_of` is when the newest
         * reading was taken, and `stale` says whether that is too long ago
         * to trust.
         */
        $engineer = $this->userWith(UserRole::Engineer);
        $ap = AccessPoint::factory()->create();

        $empty = $this->actingAs($engineer)->getJson(route('api.dashboard'))->json();
        $this->assertNull($empty['readings_as_of']);
        $this->assertFalse($empty['stale']);

        TelemetryReading::factory()->create(['access_point_id' => $ap->id, 'recorded_at' => now()->subHours(3)]);
        $old = $this->actingAs($engineer)->getJson(route('api.dashboard'))->json();
        $this->assertNotNull($old['readings_as_of']);
        $this->assertTrue($old['stale']);

        TelemetryReading::factory()->create(['access_point_id' => $ap->id, 'recorded_at' => now()]);
        $fresh = $this->actingAs($engineer)->getJson(route('api.dashboard'))->json();
        $this->assertFalse($fresh['stale']);
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

    public function test_a_removed_access_point_keeps_its_name_in_the_traffic_log(): void
    {
        // Removing an access point keeps its telemetry, so its hours stay in
        // the log. They showed a blank name linking to a page that 404s.
        $ap = AccessPoint::factory()->create();
        $this->hoursFor($ap, 3);
        $ap->delete();

        $this->actingAs($this->userWith(UserRole::Engineer))
            ->get(route('engineer.traffic.index'))
            ->assertOk()
            ->assertSee($ap->ap_code)
            ->assertDontSee(route('engineer.aps.show', $ap));
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

    // ------------------------------------------------- the backfill

    public function test_the_backfill_leaves_each_access_point_agreeing_with_its_own_newest_reading(): void
    {
        /*
         * The backfill writes reading rows with insert() and bypasses
         * TelemetryService::store(), which is the only thing that normally
         * keeps `access_points.status` and `last_seen_at` in step with them.
         * So the cache kept whatever it had while the history said something
         * else, and the AP health table — which reads status from the access
         * point and latency and loss from the newest reading — rendered rows
         * that contradicted themselves: Online, no latency, 100% packet loss.
         *
         * These access points start Offline and never seen, which is the state
         * the disagreement is visible in.
         */
        $aps = AccessPoint::factory()->count(3)->create([
            'status' => ApStatus::Offline,
            'last_seen_at' => null,
        ]);

        Artisan::call('atlas:backfill-telemetry', ['--days' => 1, '--minutes' => 60]);

        foreach ($aps as $ap) {
            $ap->refresh();

            $newest = TelemetryReading::query()
                ->where('access_point_id', $ap->id)
                ->orderByDesc('recorded_at')
                ->first();

            $this->assertNotNull($newest, "{$ap->ap_code} got no readings at all.");

            // A reading's `status` is a plain string, deliberately: it records
            // a measured state and never carries `maintenance`.
            $this->assertSame(
                $newest->status,
                $ap->status->value,
                "{$ap->ap_code} reports {$ap->status->value} while its newest reading says {$newest->status}.",
            );

            $lastAnswered = TelemetryReading::query()
                ->where('access_point_id', $ap->id)
                ->whereNotNull('latency_ms')
                ->orderByDesc('recorded_at')
                ->first();

            if ($lastAnswered) {
                $this->assertSame(
                    $lastAnswered->recorded_at->toDateTimeString(),
                    $ap->last_seen_at?->toDateTimeString(),
                    "{$ap->ap_code} was last heard from at {$lastAnswered->recorded_at}.",
                );
            }
        }
    }

    public function test_the_backfill_never_overrules_a_newer_live_reading(): void
    {
        /*
         * THE BUG THIS PINS. A backfill fills history BEHIND whatever the live
         * collector already recorded. Its last reading lands on the last whole
         * hour; the collector may have written one minutes ago. The first
         * version of settle() took "the last reading I wrote" to mean "the
         * newest reading", and on a live database it took an access point the
         * collector had just marked Offline — real outage, ticket raised,
         * engineers alerted — and flipped it back to Online from an invented
         * reading an hour older. It hid the outage.
         *
         * The previous test could not see this: it only ever had a backfill
         * and nothing newer.
         *
         * Frozen at half past the hour. In the first minute of an hour the
         * backfill's last reading, on the hour, is newer than a reading made a
         * minute ago, and the test failed for one minute in every sixty.
         */
        $this->travelTo(now()->startOfHour()->addMinutes(30));

        $ap = AccessPoint::factory()->create(['status' => ApStatus::Offline]);

        // The collector's reading, newer than anything the backfill will
        // write: an outage, a minute ago.
        TelemetryReading::create([
            'access_point_id' => $ap->id,
            'recorded_at' => now()->subMinute(),
            'latency_ms' => null,
            'client_count' => 0,
            'packet_loss_pct' => 100,
            'channel_util_pct' => 0,
            'throughput_mbps' => 0,
            'status' => 'offline',
            'source' => 'simulated',
        ]);

        // Hourly steps, so the backfill's last reading is on the last whole
        // hour, before the one above.
        Artisan::call('atlas:backfill-telemetry', ['--days' => 1, '--minutes' => 60]);

        $this->assertSame(
            ApStatus::Offline,
            $ap->fresh()->status,
            'The backfill overruled a newer live reading and hid an outage.',
        );
    }

    public function test_running_the_backfill_twice_does_not_duplicate_history(): void
    {
        /*
         * Nothing stopped a second run writing the same history again, and the
         * simulator's packet loss is random, so the copies could disagree about
         * the same instant. The development database had 18,830 of them.
         */
        AccessPoint::factory()->count(2)->create();

        Artisan::call('atlas:backfill-telemetry', ['--days' => 1, '--minutes' => 60]);
        $afterFirst = TelemetryReading::count();

        Artisan::call('atlas:backfill-telemetry', ['--days' => 1, '--minutes' => 60]);

        $this->assertSame($afterFirst, TelemetryReading::count(), 'A second backfill wrote history that was already there.');

        $duplicated = TelemetryReading::query()
            ->selectRaw('access_point_id, recorded_at, COUNT(*) as n')
            ->groupBy('access_point_id', 'recorded_at')
            ->havingRaw('COUNT(*) > 1')
            ->count();

        $this->assertSame(0, $duplicated);
    }

    public function test_the_backfill_does_not_invent_a_sighting_for_kit_under_maintenance(): void
    {
        // scopeCollectable() excludes it, so it gets no readings — and its own
        // columns must be left exactly as an administrator set them.
        $ap = AccessPoint::factory()->create([
            'status' => ApStatus::Maintenance,
            'last_seen_at' => null,
        ]);

        AccessPoint::factory()->create();

        Artisan::call('atlas:backfill-telemetry', ['--days' => 1, '--minutes' => 60]);

        $ap->refresh();

        $this->assertSame(ApStatus::Maintenance, $ap->status);
        $this->assertNull($ap->last_seen_at);
        $this->assertSame(0, TelemetryReading::where('access_point_id', $ap->id)->count());
    }
}
