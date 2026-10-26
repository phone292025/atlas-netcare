<?php

namespace Tests\Feature\Telemetry;

use App\Enums\AlarmType;
use App\Enums\NetworkStatus;
use App\Enums\SimulationScenario;
use App\Enums\UserRole;
use App\Models\AccessPoint;
use App\Models\Alarm;
use App\Models\AuditLog;
use App\Models\TelemetryReading;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Telemetry\NetworkMonitorService;
use App\Services\Telemetry\SimulatedTelemetrySource;
use App\Services\Telemetry\TelemetrySource;
use App\Support\CampusClock;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * The Network monitor (FR-312 to FR-314): the page for all three roles, the
 * snapshot behind it, and the simulation controls.
 *
 * Scenario tests go through the real simulator and the real collector, so what
 * they prove is that the rest of the system reacts to a simulated fault the way
 * it reacts to a real one — classification, tickets, alarms — not merely that a
 * column was written.
 *
 * @see App\Services\Telemetry\SimulationService
 * @see App\Services\Telemetry\NetworkMonitorService
 */
class NetworkMonitorTest extends TestCase
{
    use RefreshDatabase;

    private function userWith(UserRole $role): User
    {
        $user = User::factory()->create();
        $user->forceFill(['role' => $role, 'is_active' => true])->save();

        return $user->fresh();
    }

    /**
     * Move the clock to a twenty-minute window in which this access point is
     * not in one of the simulator's own random outages.
     *
     * isOutage() is seeded from the row id, which differs between SQLite and
     * MariaDB, so a test that assumed "it answers" would pass on one and fail
     * on the other. Outages are sticky per window, so every reading inside the
     * window this returns will answer.
     */
    private function travelToAWindowWhereItAnswers(AccessPoint $ap): void
    {
        $source = app(SimulatedTelemetrySource::class);
        $at = Carbon::parse('2026-09-16 02:00:00'); // on a window boundary

        for ($i = 0; $i < 30; $i++, $at = $at->copy()->addMinutes(20)) {
            if (! $source->reading($ap->fresh(), $at)->unreachable) {
                $this->travelTo($at);

                return;
            }
        }

        $this->fail("{$ap->ap_code} was in an outage for ten hours straight; the simulator is broken.");
    }

    private function driverThatIsNotTheSimulator(): void
    {
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
    }

    // --- The page -----------------------------------------------------------

    public function test_every_role_reaches_the_page_through_its_own_route(): void
    {
        AccessPoint::factory()->count(3)->create();

        foreach ([
            'engineer.monitor.index' => UserRole::Engineer,
            'admin.monitor.index' => UserRole::Admin,
            'assistant.monitor.index' => UserRole::Assistant,
        ] as $route => $role) {
            $this->actingAs($this->userWith($role))
                ->get(route($route))
                ->assertOk()
                ->assertSee('Network monitor')
                ->assertSee('Network activity')
                ->assertSee('href="'.route($route).'"', escape: false);
        }
    }

    public function test_an_assistant_gets_the_page_without_the_controls(): void
    {
        $this->actingAs($this->userWith(UserRole::Assistant))
            ->get(route('assistant.monitor.index'))
            ->assertOk()
            ->assertDontSee('Generate sample')
            ->assertDontSee('Run simulation')
            ->assertDontSee(route('monitor.simulate'), escape: false);
    }

    public function test_engineers_and_administrators_get_the_controls(): void
    {
        foreach (['engineer.monitor.index' => UserRole::Engineer, 'admin.monitor.index' => UserRole::Admin] as $route => $role) {
            $this->actingAs($this->userWith($role))
                ->get(route($route))
                ->assertOk()
                ->assertSee('Generate sample')
                ->assertSee('Run simulation')
                ->assertSee('Simulation control room');
        }
    }

    public function test_the_controls_are_hidden_when_the_driver_is_not_the_simulator(): void
    {
        $this->driverThatIsNotTheSimulator();

        $this->actingAs($this->userWith(UserRole::Engineer))
            ->get(route('engineer.monitor.index'))
            ->assertOk()
            ->assertSee('Live feed')
            ->assertDontSee('Generate sample')
            ->assertDontSee('Run simulation');
    }

    public function test_every_role_reaches_it_from_their_own_sidebar(): void
    {
        /*
         * Three routes, one page, and the only reason for the three is that
         * the navigation map in config/atlas.php must name each role's own
         * route — AtlasConfigTest refuses an entry namespaced under another
         * role. A route nothing links to is a page nobody can find, so this
         * asserts the sidebar entry and not only the response code.
         *
         * The sidebar link to this route, marked as the current page, so
         * this cannot pass on the page's own heading.
         */
        foreach ([
            'assistant.monitor.index' => UserRole::Assistant,
            'engineer.monitor.index' => UserRole::Engineer,
            'admin.monitor.index' => UserRole::Admin,
        ] as $route => $role) {
            $page = $this->actingAs($this->userWith($role))
                ->get(route($route))
                ->assertOk()
                ->assertSee('Network monitor')
                ->getContent();

            $this->assertMatchesRegularExpression('/href="'.preg_quote(route($route), '/').'"\s+aria-current="page"/', $page);
        }
    }

    // --- The snapshot -------------------------------------------------------

    public function test_an_access_point_with_no_recent_reading_is_unknown_not_its_old_status(): void
    {
        $fresh = AccessPoint::factory()->create(['ap_code' => 'AP-FRESH']);
        $old = AccessPoint::factory()->create(['ap_code' => 'AP-OLD']);
        $never = AccessPoint::factory()->create(['ap_code' => 'AP-NEVER']);
        $offline = AccessPoint::factory()->create(['ap_code' => 'AP-DOWN', 'status' => NetworkStatus::Offline]);
        $held = AccessPoint::factory()->create(['ap_code' => 'AP-HELD', 'status' => NetworkStatus::Maintenance]);

        TelemetryReading::factory()->create(['access_point_id' => $fresh->id, 'recorded_at' => now()->subMinute(), 'client_count' => 12]);
        TelemetryReading::factory()->create(['access_point_id' => $old->id, 'recorded_at' => now()->subHours(2), 'client_count' => 30]);
        TelemetryReading::factory()->create(['access_point_id' => $offline->id, 'recorded_at' => now()->subHours(2)]);

        $rows = collect(app(NetworkMonitorService::class)->snapshot()['access_points'])->keyBy('code');

        $this->assertSame('online', $rows['AP-FRESH']['state']);
        $this->assertSame(12, $rows['AP-FRESH']['clients']);

        $this->assertSame('stale', $rows['AP-OLD']['state']);
        $this->assertNull($rows['AP-OLD']['clients'], 'A two-hour-old client count beside "unknown" would contradict it.');

        $this->assertSame('stale', $rows['AP-NEVER']['state']);

        // Decisions the system has already made are shown as they are.
        $this->assertSame('offline', $rows['AP-DOWN']['state']);
        $this->assertSame('maintenance', $rows['AP-HELD']['state']);

        // Nothing is written: stale is a display state only.
        $this->assertSame(NetworkStatus::Online, $old->fresh()->status);
    }

    public function test_the_counts_add_up_to_the_estate(): void
    {
        AccessPoint::factory()->count(2)->create();
        AccessPoint::factory()->create(['status' => NetworkStatus::Offline]);
        AccessPoint::factory()->create(['status' => NetworkStatus::Maintenance]);

        $counts = app(NetworkMonitorService::class)->snapshot()['counts'];

        $this->assertSame(4, $counts['total']);
        $this->assertSame(
            $counts['total'],
            $counts['online'] + $counts['degraded'] + $counts['offline'] + $counts['maintenance'] + $counts['stale'],
        );
    }

    public function test_activity_averages_each_access_point_within_a_bucket_then_sums_them(): void
    {
        /*
         * One bucket, three rows from AP-A and one from AP-B. Summing the rows
         * would say 60 clients; the campus had 15 + 30 = 45 at a time.
         */
        $this->travelTo(Carbon::parse('2026-09-16 10:08:00'));

        $a = AccessPoint::factory()->create();
        $b = AccessPoint::factory()->create();

        foreach ([[$a, '10:01', 10, 4.0], [$a, '10:03', 15, 6.0], [$a, '10:05', 20, 8.0], [$b, '10:02', 30, 10.0]] as [$ap, $time, $clients, $mbps]) {
            TelemetryReading::factory()->create([
                'access_point_id' => $ap->id,
                'recorded_at' => Carbon::parse("2026-09-16 {$time}:00"),
                'client_count' => $clients,
                'throughput_mbps' => $mbps,
            ]);
        }

        $activity = app(NetworkMonitorService::class)->snapshot()['activity'];

        $this->assertCount(1, $activity);
        $this->assertSame(45, $activity[0]['clients']);
        $this->assertSame(16.0, $activity[0]['throughput']);
        // Labelled on the campus clock, not in UTC.
        $this->assertSame(
            Carbon::parse('2026-09-16 10:00:00', 'UTC')->campus()->format('H:i'),
            $activity[0]['label'],
        );
    }

    public function test_activity_ignores_readings_older_than_a_day(): void
    {
        $ap = AccessPoint::factory()->create();
        TelemetryReading::factory()->create(['access_point_id' => $ap->id, 'recorded_at' => now()->subDays(2)]);

        $this->assertSame([], app(NetworkMonitorService::class)->snapshot()['activity']);
    }

    public function test_the_polling_endpoint_returns_the_page_data(): void
    {
        AccessPoint::factory()->count(2)->create();

        $this->actingAs($this->userWith(UserRole::Assistant))
            ->getJson(route('api.monitor'))
            ->assertOk()
            ->assertJsonStructure([
                'generated_at', 'last_sample_at', 'counts' => ['total', 'online', 'degraded', 'offline', 'maintenance', 'stale'],
                'access_points' => [['id', 'code', 'state', 'state_label', 'badge', 'clients', 'utilization', 'latency_ms']],
                'activity',
            ])
            ->assertJsonPath('counts.total', 2);
    }

    public function test_the_polling_endpoints_require_signing_in(): void
    {
        $ap = AccessPoint::factory()->create();

        $this->getJson(route('api.monitor'))->assertUnauthorized();
        $this->getJson(route('api.monitor.aps.show', $ap))->assertUnauthorized();
    }

    public function test_one_access_point_comes_with_its_own_activity(): void
    {
        $ap = AccessPoint::factory()->create(['ip_address' => '10.20.1.11']);
        TelemetryReading::factory()->create(['access_point_id' => $ap->id, 'recorded_at' => now()->subMinutes(2), 'latency_ms' => 21]);

        $this->actingAs($this->userWith(UserRole::Assistant))
            ->getJson(route('api.monitor.aps.show', $ap))
            ->assertOk()
            ->assertJsonPath('code', $ap->ap_code)
            ->assertJsonPath('ip_address', '10.20.1.11')
            ->assertJsonPath('latency_ms', 21)
            ->assertJsonCount(1, 'activity');
    }

    // --- Generate sample ----------------------------------------------------

    public function test_a_sample_takes_one_reading_from_every_polled_access_point(): void
    {
        AccessPoint::factory()->count(3)->create();
        AccessPoint::factory()->create(['status' => NetworkStatus::Maintenance]);

        $this->actingAs($this->userWith(UserRole::Engineer))
            ->from(route('engineer.monitor.index'))
            ->post(route('monitor.sample'))
            ->assertRedirect(route('engineer.monitor.index'))
            ->assertSessionHas('success');

        $this->assertSame(3, TelemetryReading::count(), 'Maintenance is not polled, by the scheduler or by the button.');
    }

    public function test_an_assistant_cannot_take_a_sample(): void
    {
        AccessPoint::factory()->create();

        $this->actingAs($this->userWith(UserRole::Assistant))
            ->post(route('monitor.sample'))
            ->assertForbidden();

        $this->assertSame(0, TelemetryReading::count());
    }

    public function test_a_sample_is_refused_against_a_real_network(): void
    {
        $this->driverThatIsNotTheSimulator();
        AccessPoint::factory()->create();

        $this->actingAs($this->userWith(UserRole::Admin))
            ->post(route('monitor.sample'))
            ->assertSessionHas('error');

        $this->assertSame(0, TelemetryReading::count());
    }

    // --- Scenarios ----------------------------------------------------------

    public function test_an_outage_takes_the_access_point_offline_and_raises_one_ticket(): void
    {
        $admin = $this->userWith(UserRole::Admin);
        $this->userWith(UserRole::Engineer);
        $ap = AccessPoint::factory()->create(['last_seen_at' => now()->subMinute()]);

        $this->actingAs($admin)
            ->post(route('monitor.simulate'), ['scenario' => 'outage', 'access_point_id' => $ap->id])
            ->assertSessionHas('success');

        $ap->refresh();
        $this->assertSame(SimulationScenario::Outage, $ap->simulation_scenario);
        $this->assertNotNull($ap->simulation_started_at);
        $this->assertSame(NetworkStatus::Offline, $ap->status, 'The scenario takes a round at once, so the outage shows immediately.');

        $this->assertSame(1, Ticket::query()->where('ticketable_id', $ap->id)->count());
        $this->assertTrue(Alarm::query()->where('type', AlarmType::ApOffline)->exists());
    }

    public function test_congestion_turns_an_access_point_degraded_only_after_the_usual_run_of_breaches(): void
    {
        $ap = AccessPoint::factory()->create(['client_capacity' => 50]);
        $this->travelToAWindowWhereItAnswers($ap);
        $engineer = $this->userWith(UserRole::Engineer);

        $this->actingAs($engineer)
            ->post(route('monitor.simulate'), ['scenario' => 'congestion', 'access_point_id' => $ap->id]);

        $first = TelemetryReading::query()->where('access_point_id', $ap->id)->sole();
        $this->assertGreaterThan(50, $first->client_count, 'Congestion puts the access point over capacity.');
        $this->assertGreaterThan(config('atlas.thresholds.latency_ms'), $first->latency_ms);

        // One breaching reading is not enough — the consecutive rule still holds.
        $this->assertSame(NetworkStatus::Online, $ap->fresh()->status);

        $needed = (int) config('atlas.thresholds.consecutive_breach');

        for ($i = 1; $i < $needed; $i++) {
            $this->travel(1)->minutes();
            $this->actingAs($engineer)->post(route('monitor.sample'));
        }

        $this->assertSame(NetworkStatus::Degraded, $ap->fresh()->status);
    }

    public function test_a_feed_interruption_is_unknown_and_never_an_inferred_outage(): void
    {
        $ap = AccessPoint::factory()->create(['last_seen_at' => now()->subMinutes(30)]);
        $engineer = $this->userWith(UserRole::Engineer);

        $this->actingAs($engineer)
            ->post(route('monitor.simulate'), ['scenario' => 'feed_interruption', 'access_point_id' => $ap->id]);

        // Silent for half an hour, well past the offline window — but nobody
        // was listening, so the silence sweep must leave it alone.
        $this->assertSame(0, TelemetryReading::query()->where('access_point_id', $ap->id)->count());
        $this->assertSame(NetworkStatus::Online, $ap->fresh()->status);
        $this->assertSame(0, Ticket::count());

        $row = collect(app(NetworkMonitorService::class)->snapshot()['access_points'])->firstWhere('id', $ap->id);
        $this->assertSame('stale', $row['state']);
        $this->assertSame('feed_interruption', $row['scenario']);
    }

    public function test_recovery_for_all_clears_every_scenario(): void
    {
        $a = AccessPoint::factory()->create();
        $b = AccessPoint::factory()->create();
        $a->forceFill(['simulation_scenario' => SimulationScenario::Congestion, 'simulation_started_at' => now()])->save();
        $b->forceFill(['simulation_scenario' => SimulationScenario::FeedInterruption, 'simulation_started_at' => now()])->save();

        $this->actingAs($this->userWith(UserRole::Admin))
            ->post(route('monitor.simulate'), ['scenario' => 'recovery', 'access_point_id' => ''])
            ->assertSessionHas('success');

        $this->assertSame(0, AccessPoint::query()->whereNotNull('simulation_scenario')->count());
        $this->assertNull($a->fresh()->simulation_started_at);
    }

    public function test_recovery_with_nothing_to_recover_says_so(): void
    {
        AccessPoint::factory()->create();

        $this->actingAs($this->userWith(UserRole::Admin))
            ->post(route('monitor.simulate'), ['scenario' => 'recovery'])
            ->assertSessionHas('warning');
    }

    public function test_a_scenario_for_all_applies_to_every_polled_access_point(): void
    {
        AccessPoint::factory()->count(3)->create();
        $held = AccessPoint::factory()->create(['status' => NetworkStatus::Maintenance]);

        $this->actingAs($this->userWith(UserRole::Engineer))
            ->post(route('monitor.simulate'), ['scenario' => 'congestion']);

        $this->assertSame(3, AccessPoint::query()->where('simulation_scenario', 'congestion')->count());
        $this->assertNull($held->fresh()->simulation_scenario);
    }

    public function test_an_access_point_under_maintenance_cannot_be_targeted(): void
    {
        $held = AccessPoint::factory()->create(['status' => NetworkStatus::Maintenance]);

        $this->actingAs($this->userWith(UserRole::Engineer))
            ->post(route('monitor.simulate'), ['scenario' => 'outage', 'access_point_id' => $held->id])
            ->assertSessionHas('error');

        $this->assertNull($held->fresh()->simulation_scenario);
    }

    public function test_an_unknown_scenario_is_rejected(): void
    {
        $ap = AccessPoint::factory()->create();

        $this->actingAs($this->userWith(UserRole::Engineer))
            ->post(route('monitor.simulate'), ['scenario' => 'meteor_strike', 'access_point_id' => $ap->id])
            ->assertSessionHasErrors('scenario');

        $this->assertNull($ap->fresh()->simulation_scenario);
    }

    public function test_an_assistant_cannot_run_a_scenario(): void
    {
        $ap = AccessPoint::factory()->create();

        $this->actingAs($this->userWith(UserRole::Assistant))
            ->post(route('monitor.simulate'), ['scenario' => 'outage', 'access_point_id' => $ap->id])
            ->assertForbidden();

        $this->assertNull($ap->fresh()->simulation_scenario);
    }

    public function test_a_scenario_is_refused_against_a_real_network(): void
    {
        $this->driverThatIsNotTheSimulator();
        $ap = AccessPoint::factory()->create();

        $this->actingAs($this->userWith(UserRole::Admin))
            ->post(route('monitor.simulate'), ['scenario' => 'outage', 'access_point_id' => $ap->id])
            ->assertSessionHas('error');

        $this->assertNull($ap->fresh()->simulation_scenario);
    }

    public function test_who_ran_a_scenario_is_in_the_audit_log(): void
    {
        $ap = AccessPoint::factory()->create();
        $engineer = $this->userWith(UserRole::Engineer);

        $this->actingAs($engineer)
            ->post(route('monitor.simulate'), ['scenario' => 'feed_interruption', 'access_point_id' => $ap->id]);

        $entry = AuditLog::query()
            ->where('auditable_id', $ap->id)
            ->where('action', 'updated')
            ->get()
            ->first(fn (AuditLog $log) => array_key_exists('simulation_scenario', $log->getAttribute('changes') ?? []));

        $this->assertNotNull($entry, 'Forcing a fault onto the network must be attributable.');
        $this->assertSame($engineer->id, $entry->user_id);
    }

    public function test_the_simulator_never_reports_negative_utilisation(): void
    {
        /*
         * Overnight, saturation is near zero and the ±6-point jitter used to
         * push roughly one reading in seven below zero, which the monitor's
         * utilisation column printed as "-1%".
         */
        $ap = AccessPoint::factory()->create(['client_capacity' => 80]);
        $source = app(SimulatedTelemetrySource::class);
        $night = CampusClock::now()->next('Sunday')->setTime(3, 0)->utc();

        $lowest = collect(range(0, 199))
            ->map(fn (int $i) => $source->reading($ap, $night->copy()->addSeconds($i * 7)))
            ->reject(fn ($reading) => $reading->unreachable)
            ->min(fn ($reading) => $reading->channelUtilPct);

        $this->assertNotNull($lowest, 'Every overnight reading was an outage.');
        $this->assertGreaterThanOrEqual(0.0, $lowest);
    }

    public function test_the_monitor_never_shows_a_negative_utilisation_from_old_rows(): void
    {
        $ap = AccessPoint::factory()->create();
        TelemetryReading::factory()->create([
            'access_point_id' => $ap->id,
            'recorded_at' => now()->subMinute(),
            'channel_util_pct' => -1.4,
        ]);

        $row = collect(app(NetworkMonitorService::class)->snapshot()['access_points'])->firstWhere('id', $ap->id);

        $this->assertSame(0, $row['utilization']);
    }

    public function test_a_scenario_does_not_rewrite_history_before_it_started(): void
    {
        $ap = AccessPoint::factory()->create();
        $this->travelToAWindowWhereItAnswers($ap);
        // Ten minutes into the window, so five minutes back is still inside it.
        $this->travel(10)->minutes();

        $ap->forceFill(['simulation_scenario' => SimulationScenario::Outage, 'simulation_started_at' => now()])->save();

        $source = app(SimulatedTelemetrySource::class);

        $this->assertFalse(
            $source->reading($ap, now()->subMinutes(5))->unreachable,
            'The backfill walks the same model backwards; an outage started now must not appear an hour ago.',
        );
        $this->assertTrue($source->reading($ap, now())->unreachable);
    }
}
