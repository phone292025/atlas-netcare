<?php

namespace Tests\Feature\Api;

use App\Enums\DeviceKind;
use App\Enums\NetworkStatus;
use App\Enums\UserRole;
use App\Models\AccessPoint;
use App\Models\Agent;
use App\Models\NetworkDevice;
use App\Models\TelemetryReading;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Telemetry\AgentService;
use App\Services\Telemetry\ReadingIngest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * POST /api/v1/readings, the endpoint probe agents push readings to
 * (ADR-0017, roadmap task 1.3).
 *
 * It is the first thing outside Atlas can write to, so the tests start with
 * who gets in and what is refused, then what a stored reading does.
 */
class ReadingIngestTest extends TestCase
{
    use RefreshDatabase;

    private Agent $agent;

    private string $token;

    private AccessPoint $ap;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-10-28 10:00:00'));

        ['agent' => $this->agent, 'token' => $this->token] = app(AgentService::class)->register('Level 6 probe');
        $this->ap = AccessPoint::factory()->create([
            'code' => 'AP-06',
            'agent_id' => $this->agent->id,
            'status' => NetworkStatus::Online,
        ]);

        // Atlas attributes automatic tickets to the first administrator.
        $admin = User::factory()->create();
        $admin->forceFill(['role' => UserRole::Admin, 'is_active' => true])->save();
    }

    /** @param  array<string, mixed>  $overrides */
    private function reading(int $sequence, array $overrides = []): array
    {
        return array_merge([
            'sequence' => $sequence,
            'device' => 'AP-06',
            'measured_at' => now()->subSeconds(30)->toIso8601ZuluString(),
            'reachable' => true,
            'latency_ms' => 12.4,
            'packet_loss_pct' => 0,
            'client_count' => 9,
            'channel_util_pct' => 21.5,
            'throughput_mbps' => 30.2,
        ], $overrides);
    }

    /** @param  list<array<string, mixed>>  $readings */
    private function send(array $readings, ?string $token = null, array $batch = []): TestResponse
    {
        return $this->postJson(route('agent.readings'), array_merge([
            'sent_at' => now()->toIso8601ZuluString(),
            'agent_version' => '0.1.0',
            'readings' => $readings,
        ], $batch), ['Authorization' => 'Bearer '.($token ?? $this->token)]);
    }

    // ------------------------------------------------------------ who gets in

    public function test_without_a_token_nothing_is_stored(): void
    {
        $this->postJson(route('agent.readings'), ['sent_at' => now()->toIso8601ZuluString(), 'readings' => [$this->reading(1)]])
            ->assertUnauthorized()
            ->assertHeader('WWW-Authenticate', 'Bearer realm="atlas"');

        $this->send([$this->reading(1)], 'atl_'.str_repeat('x', 40))->assertUnauthorized();

        $this->assertSame(0, TelemetryReading::count());
    }

    public function test_a_token_in_the_query_string_is_not_accepted(): void
    {
        // It would end up in server logs and browser history.
        $this->postJson(route('agent.readings', ['token' => $this->token]), [
            'sent_at' => now()->toIso8601ZuluString(),
            'readings' => [],
        ])->assertUnauthorized();
    }

    public function test_a_retired_agent_is_turned_away(): void
    {
        app(AgentService::class)->retire($this->agent);

        $this->send([])->assertUnauthorized();
    }

    public function test_the_token_opens_no_web_screen(): void
    {
        $this->get(route('home'), ['Authorization' => 'Bearer '.$this->token])->assertRedirect(route('login'));
    }

    public function test_the_api_sets_no_session_cookie(): void
    {
        $response = $this->send([]);

        $response->assertOk();
        $this->assertNull(collect($response->headers->getCookies())->first(fn ($c) => str_contains($c->getName(), 'session')));
    }

    // ---------------------------------------------------------- what is refused

    public function test_a_malformed_batch_is_refused_whole(): void
    {
        $this->send([], batch: ['sent_at' => 'yesterday-ish'])->assertUnprocessable()->assertJsonValidationErrors('sent_at');
        $this->send([$this->reading(1, ['packet_loss_pct' => 300])])->assertUnprocessable()->assertJsonValidationErrors('readings.0.packet_loss_pct');
        $this->send([$this->reading(1, ['sql' => 'drop table'])])->assertUnprocessable();
        $this->send([$this->reading(1), $this->reading(1)])->assertUnprocessable()->assertJsonValidationErrors('readings.0.sequence');

        $this->assertSame(0, TelemetryReading::count());
    }

    public function test_a_batch_is_capped_in_readings_and_in_size(): void
    {
        config(['atlas.ingest.max_readings' => 2, 'atlas.ingest.max_kilobytes' => 1]);

        $this->send([$this->reading(1), $this->reading(2), $this->reading(3)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('readings');

        $this->send([], batch: ['agent_version' => str_repeat('x', 2048)])->assertStatus(413);
    }

    public function test_another_agents_device_and_an_unknown_one_get_the_same_answer(): void
    {
        $other = Agent::factory()->create();
        NetworkDevice::factory()->kind(DeviceKind::Switch)->create(['code' => 'SW-ELSEWHERE', 'agent_id' => $other->id]);

        $this->send([
            $this->reading(1, ['device' => 'SW-ELSEWHERE']),
            $this->reading(2, ['device' => 'NO-SUCH-THING']),
        ])->assertOk()->assertJson([
            'stored' => [],
            'rejected' => [
                ['sequence' => 1, 'reason' => ReadingIngest::NOT_WATCHED],
                ['sequence' => 2, 'reason' => ReadingIngest::NOT_WATCHED],
            ],
        ]);

        $this->assertSame(0, TelemetryReading::count());
    }

    public function test_readings_from_the_future_or_too_long_ago_are_refused(): void
    {
        $response = $this->send([
            $this->reading(1, ['measured_at' => now()->addMinutes(10)->toIso8601ZuluString()]),
            $this->reading(2, ['measured_at' => now()->subDays(8)->toIso8601ZuluString()]),
            $this->reading(3),
        ])->assertOk();

        $this->assertSame([3], $response->json('stored'));
        $this->assertSame([1, 2], array_column($response->json('rejected'), 'sequence'));
    }

    public function test_one_agent_is_limited_in_how_often_it_sends(): void
    {
        config(['atlas.ingest.per_agent_per_minute' => 2]);

        $this->send([])->assertOk();
        $this->send([])->assertOk();
        $this->send([])->assertTooManyRequests();
    }

    // ------------------------------------------------- what a stored one does

    public function test_a_stored_reading_records_who_sent_it_and_when(): void
    {
        $this->send([$this->reading(41)])->assertOk()->assertJson(['stored' => [41], 'duplicate' => [], 'rejected' => []]);

        $row = TelemetryReading::sole();
        $this->assertSame($this->ap->id, $row->network_device_id);
        $this->assertSame($this->agent->id, (int) $row->agent_id);
        $this->assertSame(41, (int) $row->sequence);
        $this->assertSame('live', $row->source);
        $this->assertSame('2026-10-28 09:59:30', $row->recorded_at->toDateTimeString());
        $this->assertSame('2026-10-28 10:00:00', (string) $row->received_at);

        $ap = $this->ap->fresh();
        $this->assertSame('2026-10-28 09:59:30', $ap->last_seen_at->toDateTimeString());
        $this->assertSame('2026-10-28 09:59:30', $ap->last_reading_at->toDateTimeString());
    }

    public function test_a_batch_sent_twice_is_stored_once(): void
    {
        $batch = [$this->reading(1), $this->reading(2, ['measured_at' => now()->subSeconds(10)->toIso8601ZuluString()])];

        $this->send($batch)->assertJson(['stored' => [1, 2]]);
        $this->send($batch)->assertJson(['stored' => [], 'duplicate' => [1, 2]]);

        $this->assertSame(2, TelemetryReading::count());
    }

    public function test_every_batch_is_a_heartbeat_and_carries_the_agents_clock(): void
    {
        $this->send([], batch: ['sent_at' => now()->addSeconds(45)->toIso8601ZuluString(), 'agent_version' => '0.2.0'])
            ->assertOk()
            ->assertJson(['clock_offset_ms' => 45000]);

        $agent = $this->agent->fresh();
        $this->assertTrue($agent->last_seen_at->eq(now()));
        $this->assertSame('0.2.0', $agent->version);
        $this->assertTrue($agent->clockIsOff(), 'Forty-five seconds out should be flagged.');
    }

    public function test_a_device_that_stops_answering_goes_offline_and_gets_a_ticket(): void
    {
        $this->send([$this->reading(1, ['reachable' => false, 'latency_ms' => null])])->assertOk();

        $this->assertSame(NetworkStatus::Offline, $this->ap->fresh()->status);
        $this->assertSame(1, Ticket::where('ticketable_id', $this->ap->id)->count());

        $row = TelemetryReading::sole();
        $this->assertNull($row->latency_ms);
        $this->assertSame('100.00', (string) $row->packet_loss_pct);
    }

    public function test_a_late_reading_fills_history_without_moving_the_status(): void
    {
        $this->send([$this->reading(2)])->assertOk();

        // Measured before the one already stored, and unreachable then.
        $this->send([$this->reading(1, [
            'reachable' => false,
            'measured_at' => now()->subMinutes(20)->toIso8601ZuluString(),
        ])])->assertJson(['stored' => [1]]);

        $this->assertSame(NetworkStatus::Online, $this->ap->fresh()->status);
        $this->assertSame(0, Ticket::count());
        $this->assertSame(2, TelemetryReading::count());
    }

    public function test_a_buffered_batch_is_judged_reading_by_reading(): void
    {
        // Three slow readings in a row is degraded, even when all three
        // arrive together because the agent was offline.
        $slow = fn (int $seq, int $minutesAgo) => $this->reading($seq, [
            'latency_ms' => 400,
            'measured_at' => now()->subMinutes($minutesAgo)->toIso8601ZuluString(),
        ]);

        $this->send([$slow(3, 1), $slow(1, 3), $slow(2, 2)])->assertJson(['stored' => [1, 2, 3]]);

        $this->assertSame(NetworkStatus::Degraded, $this->ap->fresh()->status);
        $this->assertSame(
            ['online', 'online', 'degraded'],
            TelemetryReading::orderBy('recorded_at')->pluck('status')->all(),
        );
    }

    public function test_a_switch_reports_without_clients(): void
    {
        NetworkDevice::factory()->kind(DeviceKind::Switch)->create(['code' => 'SW-L6', 'agent_id' => $this->agent->id]);

        $this->send([[
            'sequence' => 7,
            'device' => 'sw-l6',
            'measured_at' => now()->subSeconds(5)->toIso8601ZuluString(),
            'reachable' => true,
            'latency_ms' => 1.2,
        ]])->assertJson(['stored' => [7]]);

        $this->assertSame(NetworkStatus::Online, NetworkDevice::firstWhere('code', 'SW-L6')->status);
    }
}
