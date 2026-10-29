<?php

namespace Tests\Feature\Telemetry;

use App\Enums\AlarmType;
use App\Enums\NetworkStatus;
use App\Enums\UserRole;
use App\Models\AccessPoint;
use App\Models\Agent;
use App\Models\Alarm;
use App\Models\TelemetryReading;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Telemetry\AgentService;
use App\Services\Telemetry\NetworkMonitorService;
use App\Services\Telemetry\TelemetryCollector;
use App\Services\Telemetry\TelemetryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The agent heartbeat (roadmap task 1.6). A probe agent that stops reporting
 * leaves its devices Unknown rather than on their last status, and raises one
 * alert per silence. A reading from the device is what changes it back.
 *
 * @see App\Services\Telemetry\AgentService::noticeSilence()
 * @see App\Services\Telemetry\TelemetryService::markUnknown()
 */
class AgentHeartbeatTest extends TestCase
{
    use RefreshDatabase;

    private Agent $agent;

    private string $token;

    private User $admin;

    private User $engineer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-10-29 10:00:00'));

        ['agent' => $this->agent, 'token' => $this->token] = app(AgentService::class)->register('Level 6 probe');

        $this->admin = $this->userWith(UserRole::Admin);
        $this->engineer = $this->userWith(UserRole::Engineer);
    }

    private function userWith(UserRole $role): User
    {
        $user = User::factory()->create();
        $user->forceFill(['role' => $role, 'is_active' => true])->save();

        return $user->fresh();
    }

    private function heardFrom(Agent $agent, Carbon $at): void
    {
        $agent->forceFill(['last_seen_at' => $at])->save();
    }

    private function watchedBy(Agent $agent, NetworkStatus $status = NetworkStatus::Online, string $code = 'AP-06'): AccessPoint
    {
        return AccessPoint::factory()->create([
            'code' => $code,
            'agent_id' => $agent->id,
            'status' => $status,
            'last_seen_at' => now()->subMinutes(10),
        ]);
    }

    /** @param  array<string, mixed>  $overrides */
    private function send(array $overrides = []): TestResponse
    {
        return $this->postJson(route('agent.readings'), [
            'sent_at' => now()->toIso8601ZuluString(),
            'agent_version' => '0.1.0',
            'readings' => [array_merge([
                'sequence' => 1,
                'device' => 'AP-06',
                'measured_at' => now()->subSeconds(20)->toIso8601ZuluString(),
                'reachable' => true,
                'latency_ms' => 12,
                'packet_loss_pct' => 0,
                'client_count' => 9,
            ], $overrides)],
        ], ['Authorization' => 'Bearer '.$this->token]);
    }

    private function silentAlarms(): int
    {
        return Alarm::where('type', AlarmType::AgentSilent)->count();
    }

    // --------------------------------------------------- devices go unknown

    public function test_a_silent_agents_devices_turn_unknown(): void
    {
        $this->heardFrom($this->agent, now()->subMinutes(10));

        $online = $this->watchedBy($this->agent, NetworkStatus::Online, 'AP-01');
        $degraded = $this->watchedBy($this->agent, NetworkStatus::Degraded, 'AP-02');

        $transitions = app(TelemetryService::class)->markUnknown();

        $this->assertSame(NetworkStatus::Unknown, $online->fresh()->status);
        $this->assertSame(NetworkStatus::Unknown, $degraded->fresh()->status);
        $this->assertCount(2, $transitions);
        $this->assertSame(NetworkStatus::Degraded, $transitions->firstWhere('device.id', $degraded->id)['from']);
    }

    public function test_offline_and_maintenance_keep_their_status(): void
    {
        // A ticket hangs on Offline, and a person set Maintenance.
        $this->heardFrom($this->agent, now()->subMinutes(10));

        $offline = $this->watchedBy($this->agent, NetworkStatus::Offline, 'AP-01');
        $held = $this->watchedBy($this->agent, NetworkStatus::Maintenance, 'AP-02');

        app(TelemetryService::class)->markUnknown();

        $this->assertSame(NetworkStatus::Offline, $offline->fresh()->status);
        $this->assertSame(NetworkStatus::Maintenance, $held->fresh()->status);
    }

    public function test_only_silent_agents_devices_change(): void
    {
        $this->heardFrom($this->agent, now()->subMinute());
        $connected = $this->watchedBy($this->agent, code: 'AP-01');

        $simulated = AccessPoint::factory()->create(['code' => 'AP-02', 'status' => NetworkStatus::Online]);
        $unwatched = AccessPoint::factory()->create(['code' => 'AP-03', 'status' => NetworkStatus::Online, 'agent_id' => null]);

        $this->assertCount(0, app(TelemetryService::class)->markUnknown());

        foreach ([$connected, $simulated, $unwatched] as $ap) {
            $this->assertSame(NetworkStatus::Online, $ap->fresh()->status, "{$ap->code} changed.");
        }
    }

    public function test_a_device_given_to_an_agent_that_never_connected_is_unknown_without_an_alert(): void
    {
        // Nobody measures it yet, so its status is a guess. Nothing stopped,
        // though: the agent hasn't started, which the agents screen says.
        $ap = $this->watchedBy($this->agent);

        $this->artisan('atlas:collect-telemetry')->assertSuccessful();

        $this->assertSame(NetworkStatus::Unknown, $ap->fresh()->status);
        $this->assertSame(0, $this->silentAlarms());
    }

    // ------------------------------------------------------------ one alert

    public function test_a_silent_agent_alerts_engineers_and_administrators_once(): void
    {
        $assistant = $this->userWith(UserRole::Assistant);
        $this->heardFrom($this->agent, now()->subMinutes(10));
        $this->watchedBy($this->agent);

        $this->artisan('atlas:collect-telemetry')->assertSuccessful();
        $this->travel(5)->minutes();
        $this->artisan('atlas:collect-telemetry')->assertSuccessful();

        $alarms = Alarm::where('type', AlarmType::AgentSilent)->get();

        $this->assertSame([$this->admin->id, $this->engineer->id], $alarms->pluck('user_id')->sort()->values()->all());
        $this->assertFalse($alarms->contains('user_id', $assistant->id));
        $this->assertSame('Level 6 probe has stopped reporting', $alarms->first()->title);
        $this->assertStringContainsString('Last heard from at 29 Oct, 17:50', $alarms->first()->message);
        $this->assertTrue($alarms->first()->related->is($this->agent));
    }

    public function test_it_waits_for_the_silence_to_last(): void
    {
        // Agents send every 30 to 60 seconds, so two missed minutes is not news.
        $this->heardFrom($this->agent, now()->subMinutes(2));
        $ap = $this->watchedBy($this->agent);
        $ap->forceFill(['last_seen_at' => now()->subMinutes(2)])->save();

        $this->artisan('atlas:collect-telemetry')->assertSuccessful();

        $this->assertSame(0, $this->silentAlarms());
        $this->assertSame(NetworkStatus::Online, $ap->fresh()->status);
    }

    public function test_hearing_from_it_ends_the_silence_and_the_next_one_alerts_again(): void
    {
        $this->heardFrom($this->agent, now()->subMinutes(10));
        $this->watchedBy($this->agent);

        $this->artisan('atlas:collect-telemetry');
        $this->assertNotNull($this->agent->fresh()->silent_since);

        // A heartbeat: an empty batch.
        $this->postJson(route('agent.readings'), [
            'sent_at' => now()->toIso8601ZuluString(),
            'readings' => [],
        ], ['Authorization' => 'Bearer '.$this->token])->assertOk();

        $this->assertNull($this->agent->fresh()->silent_since);

        $this->travel(10)->minutes();
        $this->artisan('atlas:collect-telemetry');

        $this->assertSame(4, $this->silentAlarms(), 'Two silences, two alerts each.');
    }

    public function test_noticing_a_silence_twice_at_once_alerts_once(): void
    {
        // The scheduler and a sample taken from the browser can overlap.
        $this->heardFrom($this->agent, now()->subMinutes(10));

        $first = app(AgentService::class)->noticeSilence();
        $second = app(AgentService::class)->noticeSilence();

        $this->assertCount(1, $first);
        $this->assertCount(0, $second);
    }

    // ---------------------------------------------------------- coming back

    public function test_a_reading_brings_an_unknown_device_back_without_a_ticket(): void
    {
        $ap = $this->watchedBy($this->agent, NetworkStatus::Unknown);

        $this->send()->assertOk();

        $this->assertSame(NetworkStatus::Online, $ap->fresh()->status);
        $this->assertSame(0, Ticket::count());
    }

    public function test_coming_back_unreachable_raises_the_ticket(): void
    {
        $ap = $this->watchedBy($this->agent, NetworkStatus::Unknown);

        $this->send(['reachable' => false, 'latency_ms' => null])->assertOk();

        $this->assertSame(NetworkStatus::Offline, $ap->fresh()->status);
        $this->assertSame(1, Ticket::where('ticketable_id', $ap->id)->count());
    }

    public function test_coming_back_degraded_alerts_the_engineers(): void
    {
        // Nobody was told how it was while it was unknown.
        $ap = $this->watchedBy($this->agent, NetworkStatus::Degraded);

        app(TelemetryCollector::class)->react(collect([
            ['device' => $ap, 'from' => NetworkStatus::Unknown, 'to' => NetworkStatus::Degraded],
        ]));

        $this->assertTrue(Alarm::where('type', AlarmType::ApDegraded)->where('user_id', $this->engineer->id)->exists());
    }

    public function test_the_offline_sweep_leaves_unknown_alone(): void
    {
        // Back from a silence, an agent may send its heartbeat before the
        // readings it kept. Its devices aren't down for that.
        $this->heardFrom($this->agent, now());
        $ap = $this->watchedBy($this->agent, NetworkStatus::Unknown);

        $this->assertCount(0, app(TelemetryService::class)->markStaleOffline());
        $this->assertSame(NetworkStatus::Unknown, $ap->fresh()->status);
    }

    // -------------------------------------------------------------- screens

    public function test_the_monitor_counts_an_unknown_device_as_unknown(): void
    {
        $ap = $this->watchedBy($this->agent, NetworkStatus::Unknown);

        $snapshot = app(NetworkMonitorService::class)->snapshot();
        $row = collect($snapshot['access_points'])->firstWhere('id', $ap->id);

        $this->assertSame(1, $snapshot['counts']['stale']);
        // So the page says an agent went quiet, not that the scheduler stopped.
        $this->assertSame(1, $snapshot['counts']['silent']);
        $this->assertTrue($row['silent']);
        $this->assertSame('Unknown', $row['state_label']);
        $this->assertNull($row['clients'], 'An unknown device showed numbers from before.');
    }

    public function test_the_access_point_lists_hide_an_unknown_devices_old_numbers(): void
    {
        $ap = $this->watchedBy($this->agent, NetworkStatus::Unknown);
        TelemetryReading::factory()->create([
            'network_device_id' => $ap->id,
            'recorded_at' => now()->subMinutes(20),
            'latency_ms' => 400,
            'client_count' => 30,
        ]);

        $data = app(TelemetryService::class)->dashboardData();
        $row = collect($data['access_points'])->firstWhere('id', $ap->id);

        // An agent going quiet is not the collector stopping.
        $this->assertFalse($data['stale'], 'The page would say the collector has stopped.');
        $this->assertSame(0, $data['clients_now']);

        $this->assertSame('Unknown', $row['status_label']);
        $this->assertNull($row['clients']);
        $this->assertNull($row['latency_ms']);
        $this->assertFalse($row['over_threshold'], 'A reading from before the silence still counted as slow now.');
    }

    public function test_opening_the_alert_takes_an_administrator_to_the_agent(): void
    {
        $this->heardFrom($this->agent, now()->subMinutes(10));
        $this->artisan('atlas:collect-telemetry');

        $forAdmin = Alarm::where('type', AlarmType::AgentSilent)->where('user_id', $this->admin->id)->sole();
        $forEngineer = Alarm::where('type', AlarmType::AgentSilent)->where('user_id', $this->engineer->id)->sole();

        $this->actingAs($this->admin)
            ->get(route('notifications.show', $forAdmin))
            ->assertRedirect(route('admin.agents.show', $this->agent));

        $this->actingAs($this->engineer)
            ->get(route('notifications.show', $forEngineer))
            ->assertRedirect(route('engineer.monitor.index'));
    }
}
