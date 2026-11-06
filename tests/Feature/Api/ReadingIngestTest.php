<?php

namespace Tests\Feature\Api;

use App\Enums\AlarmType;
use App\Enums\DeviceKind;
use App\Enums\NetworkStatus;
use App\Enums\UserRole;
use App\Events\DeviceStatusesChanged;
use App\Models\AccessPoint;
use App\Models\Agent;
use App\Models\Alarm;
use App\Models\Incident;
use App\Models\NetworkDevice;
use App\Models\TelemetryHourly;
use App\Models\TelemetryReading;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Telemetry\AgentService;
use App\Services\Telemetry\ReadingIngest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
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

    public function test_a_broken_batch_is_refused_whole(): void
    {
        // The request or the agent is wrong: the agent keeps every reading
        // and waits, since sending them again one by one would not help.
        $this->send([], batch: ['sent_at' => 'yesterday-ish'])->assertUnprocessable()->assertJsonValidationErrors('sent_at');
        $this->send([], batch: ['agent_version' => str_repeat('x', 41)])->assertUnprocessable()->assertJsonValidationErrors('agent_version');
        $this->send([$this->reading(1), $this->reading(1)])->assertUnprocessable()->assertJsonValidationErrors('readings.0.sequence');
        $this->send([['device' => 'AP-01', 'measured_at' => now()->toIso8601ZuluString(), 'reachable' => true]])
            ->assertUnprocessable()->assertJsonValidationErrors('readings.0.sequence');

        $this->assertSame(0, TelemetryReading::count());
    }

    public function test_a_reading_with_bad_values_is_rejected_alone_and_the_rest_are_stored(): void
    {
        // One bad reading used to refuse all 500 with 422, and the agent had
        // to guess which one. Now it is rejected by sequence, in a 200.
        $response = $this->send([
            $this->reading(1),
            $this->reading(2, ['packet_loss_pct' => 300]),
            $this->reading(3, ['reachable' => 'maybe']),
        ])->assertOk()->assertJsonPath('stored', [1]);

        $reasons = collect($response->json('rejected'))->pluck('reason', 'sequence');
        $this->assertStringContainsString('packet loss pct', $reasons[2]);
        $this->assertStringContainsString('reachable', $reasons[3]);
        $this->assertSame(1, TelemetryReading::count());
    }

    public function test_the_batch_the_go_agent_sends_is_stored_whole(): void
    {
        // agent/testdata/batch.json is what the Go agent encodes, checked by
        // agent/internal/sender/contract_test.go. Both sides test the same
        // file, so a field renamed on either side fails one of them.
        $batch = json_decode(file_get_contents(base_path('agent/testdata/batch.json')), true);

        $this->postJson(route('agent.readings'), $batch, ['Authorization' => 'Bearer '.$this->token])
            ->assertOk()
            ->assertJsonPath('stored', [1, 2])
            ->assertJsonPath('rejected', []);

        $readings = TelemetryReading::orderBy('sequence')->get();
        $this->assertSame([12, null], $readings->pluck('latency_ms')->all());
        $this->assertSame(9, $readings[0]->client_count);
        $this->assertEquals(100, $readings[1]->packet_loss_pct);
    }

    public function test_a_field_atlas_does_not_know_is_ignored_and_the_reading_stored(): void
    {
        // A newer agent measuring something more must still be heard by an
        // older Atlas. Refusing the reading would lose it for good.
        $this->send([$this->reading(1, ['signal_dbm' => -61, 'sql' => 'drop table'])])
            ->assertOk()
            ->assertJsonPath('stored', [1])
            ->assertJsonPath('rejected', []);
    }

    public function test_a_number_too_big_for_its_column_is_rejected_not_a_server_error(): void
    {
        // Both columns are smallint on PostgreSQL, which tops out at 32767.
        // Accepting more failed the insert, and the agent resent the batch forever.
        $this->send([$this->reading(1, ['latency_ms' => 40000]), $this->reading(2, ['client_count' => 40000])])
            ->assertOk()
            ->assertJsonPath('stored', [])
            ->assertJsonPath('rejected.0.sequence', 1)
            ->assertJsonPath('rejected.1.sequence', 2);

        $this->send([$this->reading(3, ['latency_ms' => 32767, 'client_count' => 32767])])->assertOk()->assertJsonPath('stored', [3]);
    }

    public function test_a_clock_years_out_is_recorded_as_far_out_not_a_server_error(): void
    {
        // A Pi with no battery clock boots in 1970. The offset in milliseconds
        // is far past what the integer column holds.
        $this->send([], batch: ['sent_at' => '2000-01-01T00:00:00Z'])
            ->assertOk()
            ->assertJsonPath('clock_offset_ms', fn (int $offset) => $offset < -2_147_483_648);

        $agent = $this->agent->fresh();
        $this->assertSame(-2_147_483_648, $agent->clock_offset_ms);
        $this->assertTrue($agent->clockIsOff());
        $this->assertNotNull($agent->last_seen_at);
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

    public function test_an_outage_whose_event_was_lost_is_ticketed_and_alerted_by_the_sweep(): void
    {
        $engineer = User::factory()->create();
        $engineer->forceFill(['role' => UserRole::Engineer, 'is_active' => true])->save();

        // The worker that should have handled the event died with it.
        Event::fake([DeviceStatusesChanged::class]);
        $this->send([$this->reading(1, ['reachable' => false, 'latency_ms' => null])])->assertOk();
        $this->assertSame(NetworkStatus::Offline, $this->ap->fresh()->status);
        $this->assertSame(0, Incident::count());

        // The sweep reads the device's status, a minute later at most.
        $this->artisan('atlas:watch-incidents')->assertSuccessful();
        $this->artisan('atlas:watch-incidents')->assertSuccessful();

        $this->assertSame(1, Incident::where('network_device_id', $this->ap->id)->count());
        $this->assertSame(1, Ticket::where('ticketable_id', $this->ap->id)->count());
        $this->assertSame(1, Alarm::where('type', AlarmType::ApOffline)->where('user_id', $engineer->id)->count());
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

    public function test_what_a_probe_did_not_measure_is_stored_as_unknown_not_zero(): void
    {
        $this->send([
            $this->reading(1, ['measured_at' => now()->subSeconds(40)->toIso8601ZuluString(), 'channel_util_pct' => 40]),
            // A ping-only probe: no clients, no loss figure, no traffic.
            ['sequence' => 2, 'device' => 'AP-06', 'measured_at' => now()->subSeconds(20)->toIso8601ZuluString(), 'reachable' => true, 'latency_ms' => 3],
        ])->assertJson(['stored' => [1, 2]]);

        $pingOnly = TelemetryReading::query()->where('sequence', 2)->sole();
        $this->assertSame(3, $pingOnly->latency_ms);
        $this->assertNull($pingOnly->client_count);
        $this->assertNull($pingOnly->packet_loss_pct);
        $this->assertNull($pingOnly->channel_util_pct);
        $this->assertNull($pingOnly->throughput_mbps);

        // The hour is the measured reading's figures, not halved by a zero.
        $hour = TelemetryHourly::query()->where('network_device_id', $this->ap->id)->sole();
        $this->assertSame(9, $hour->peak_client_count);
        $this->assertEqualsWithDelta(40.0, (float) $hour->avg_channel_util_pct, 0.01);

        // The page says it doesn't know, rather than "0 clients".
        $engineer = User::factory()->create();
        $engineer->forceFill(['role' => UserRole::Engineer, 'is_active' => true])->save();
        $this->actingAs($engineer)->get(route('engineer.aps.show', $this->ap))
            ->assertOk()
            ->assertSeeInOrder(['Clients now', '-', 'of '.$this->ap->client_capacity]);
    }

    // ------------------------------------------------------------ HTTPS for remote agents

    /** A batch from another machine on the network, over $scheme. */
    private function sendFromTheNetwork(string $scheme = 'http', array $headers = [], string $address = '192.168.1.50'): TestResponse
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $address])->postJson(
            preg_replace('/^http:/', $scheme.':', route('agent.readings')),
            ['sent_at' => now()->toIso8601ZuluString(), 'agent_version' => '0.1.0', 'readings' => [$this->reading(1)]],
            ['Authorization' => 'Bearer '.$this->token] + $headers,
        );
    }

    public function test_plain_http_from_another_machine_is_refused_before_the_token_is_read(): void
    {
        config(['atlas.published_on_loopback' => false]);

        $this->sendFromTheNetwork()->assertForbidden()->assertJsonPath('message', fn (string $m) => str_contains($m, 'HTTPS'));

        $this->assertSame(0, TelemetryReading::count());
        $this->assertNull($this->agent->fresh()->last_seen_at);
    }

    public function test_https_from_another_machine_is_accepted(): void
    {
        config(['atlas.published_on_loopback' => false]);

        $this->sendFromTheNetwork('https')->assertOk();
    }

    public function test_a_forwarded_request_over_plain_http_is_refused_even_from_this_machine(): void
    {
        // A tunnel or proxy on this machine connects from loopback, and may
        // be passing on a request from anywhere.
        config(['atlas.published_on_loopback' => false]);

        $this->sendFromTheNetwork(headers: ['X-Forwarded-For' => '203.0.113.9'], address: '127.0.0.1')->assertForbidden();
    }

    public function test_a_docker_stack_published_on_loopback_still_refuses_plain_http_from_its_gateway(): void
    {
        // Inside the container every request comes from Docker's gateway, a
        // host reverse proxy's included, so the gateway is not this machine.
        config(['atlas.published_on_loopback' => true]);

        $this->sendFromTheNetwork(address: '172.18.0.1')->assertForbidden();
    }

    public function test_an_agent_on_this_machine_without_docker_may_use_plain_http(): void
    {
        config(['atlas.published_on_loopback' => false]);

        $this->sendFromTheNetwork(address: '127.0.0.1')->assertOk();
    }

    public function test_a_port_forward_to_this_machine_is_refused_plain_http(): void
    {
        // A host port forward (netsh portproxy, ssh -R) adds no header, but
        // the request is addressed to the name the forward was reached by.
        config(['atlas.published_on_loopback' => true]);

        $this->withServerVariables(['REMOTE_ADDR' => '172.18.0.1'])->postJson(
            'http://atlas.example.org/api/v1/readings',
            ['sent_at' => now()->toIso8601ZuluString(), 'agent_version' => '0.1.0', 'readings' => [$this->reading(1)]],
            ['Authorization' => 'Bearer '.$this->token],
        )->assertForbidden();
    }

    public function test_the_owner_of_a_closed_network_can_allow_plain_http(): void
    {
        config(['atlas.published_on_loopback' => false, 'atlas.ingest.allow_plain_http' => true]);

        $this->sendFromTheNetwork()->assertOk();
    }
}
