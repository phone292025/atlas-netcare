<?php

namespace Tests\Feature\Incidents;

use App\Enums\AlarmType;
use App\Enums\AlertChannel;
use App\Enums\DeviceKind;
use App\Enums\IncidentRule;
use App\Enums\IncidentStatus;
use App\Enums\NetworkStatus;
use App\Enums\UserRole;
use App\Events\AgentSilenceChanged;
use App\Events\DeviceStatusesChanged;
use App\Models\AccessPoint;
use App\Models\Agent;
use App\Models\Alarm;
use App\Models\Incident;
use App\Models\IncidentAlert;
use App\Models\Location;
use App\Models\NetworkDevice;
use App\Models\Shift;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Incidents\IncidentAlerts;
use App\Services\Incidents\IncidentService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Tests\TestCase;

/**
 * Phase 2: incidents between readings and tickets (ADR-0014).
 *
 * Status changes are published the way Telemetry publishes them, as one
 * DeviceStatusesChanged per batch, and the queue runs listeners at once in
 * the test environment.
 */
class IncidentTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $engineer;

    private Location $room;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->userWith(UserRole::Admin);
        $this->engineer = $this->userWith(UserRole::Engineer);
        $this->room = Location::factory()->create();
    }

    private function userWith(UserRole $role): User
    {
        $user = User::factory()->create();
        $user->forceFill(['role' => $role, 'is_active' => true])->save();

        return $user->fresh();
    }

    private function switch(): NetworkDevice
    {
        return NetworkDevice::factory()->kind(DeviceKind::Switch)->create(['location_id' => $this->room->id]);
    }

    private function accessPointBehind(?NetworkDevice $parent = null): AccessPoint
    {
        return AccessPoint::factory()->create(['location_id' => $this->room->id, 'parent_id' => $parent?->id]);
    }

    /** Set statuses and publish them in one batch, the way a round does. */
    private function change(NetworkStatus $to, NetworkDevice ...$devices): void
    {
        $transitions = [];

        foreach ($devices as $device) {
            $device->refresh();
            $transitions[] = ['device' => $device, 'from' => $device->status, 'to' => $to];
            $device->forceFill(['status' => $to])->saveQuietly();
        }

        event(DeviceStatusesChanged::fromTransitions($transitions));
    }

    private function onShift(Location $where): User
    {
        $assistant = $this->userWith(UserRole::Assistant);
        Shift::factory()->checkedIn()->create(['user_id' => $assistant->id, 'location_id' => $where->id]);

        return $assistant;
    }

    private function alarmsFor(User $user): int
    {
        return Alarm::query()->where('user_id', $user->id)->where('related_type', (new Incident)->getMorphClass())->count();
    }

    // ------------------------------------------------- one fault, one incident

    public function test_a_device_that_stops_answering_opens_one_incident_with_one_ticket(): void
    {
        $ap = $this->accessPointBehind();

        $this->change(NetworkStatus::Offline, $ap);
        $this->artisan('atlas:watch-incidents')->assertSuccessful();

        $incident = Incident::sole();
        $this->assertSame(IncidentRule::Offline, $incident->rule);
        $this->assertSame(IncidentStatus::Open, $incident->status);
        $this->assertSame(1, Ticket::count());
        $this->assertSame($incident->ticket_id, Ticket::sole()->id);
        $this->assertSame("{$ap->code} is not responding", Ticket::sole()->title);
    }

    public function test_the_database_refuses_a_second_open_incident_for_one_device_and_rule(): void
    {
        $ap = $this->accessPointBehind();
        $row = ['rule' => 'offline', 'status' => 'open', 'title' => 'x', 'network_device_id' => $ap->id, 'opened_at' => now()];
        DB::table('incidents')->insert($row);

        $this->expectException(UniqueConstraintViolationException::class);

        DB::table('incidents')->insert($row);
    }

    public function test_a_device_that_recovers_resolves_its_incident_and_keeps_the_ticket(): void
    {
        $ap = $this->accessPointBehind();
        $this->change(NetworkStatus::Offline, $ap);
        $this->change(NetworkStatus::Online, $ap);

        $incident = Incident::sole();
        $this->assertSame(IncidentStatus::Resolved, $incident->status);
        $this->assertSame('recovered', $incident->resolution);
        // The fault may come back; whoever works the ticket closes it.
        $this->assertTrue(Ticket::sole()->status->isOutstanding());

        $this->change(NetworkStatus::Offline, $ap);
        $this->assertSame(2, Incident::count());
        $this->assertSame(1, Ticket::count(), 'The second outage got a second ticket while the first was still open.');
        $this->assertSame(Ticket::sole()->id, Incident::latest('id')->first()->ticket_id);
    }

    // ------------------------------------------------- grouping by parent

    public function test_a_switch_and_the_access_points_behind_it_failing_together_open_one_incident(): void
    {
        $switch = $this->switch();
        $aps = collect(range(1, 3))->map(fn () => $this->accessPointBehind($switch));

        // Children first in the batch, as a sweep might list them.
        $this->change(NetworkStatus::Offline, ...[...$aps->all(), $switch]);

        $incident = Incident::sole();
        $this->assertTrue($incident->device->is($switch));
        $this->assertSame(4, $incident->devices()->count());
        $this->assertSame(1, Ticket::count());
        $this->assertStringContainsString($switch->code, Ticket::sole()->title);
    }

    public function test_a_switch_failing_after_its_access_point_takes_the_access_points_incident_in(): void
    {
        $switch = $this->switch();
        $ap = $this->accessPointBehind($switch);

        $this->change(NetworkStatus::Offline, $ap);
        $this->change(NetworkStatus::Offline, $switch);

        $apIncident = Incident::where('network_device_id', $ap->id)->sole();
        $switchIncident = Incident::where('network_device_id', $switch->id)->sole();

        $this->assertSame('grouped', $apIncident->resolution);
        $this->assertSame($switchIncident->id, $apIncident->grouped_into_id);
        $this->assertTrue($switchIncident->devices->contains($ap));
        $this->assertNull($switchIncident->resolved_at);
    }

    public function test_an_access_point_failing_behind_a_failed_switch_joins_its_incident(): void
    {
        $switch = $this->switch();
        $ap = $this->accessPointBehind($switch);

        $this->change(NetworkStatus::Offline, $switch);
        $this->change(NetworkStatus::Offline, $ap);

        $this->assertSame(1, Incident::count());
        $this->assertSame(2, Incident::sole()->devices()->count());
    }

    public function test_when_the_switch_comes_back_an_access_point_still_down_gets_its_own_incident(): void
    {
        $switch = $this->switch();
        $fine = $this->accessPointBehind($switch);
        $broken = $this->accessPointBehind($switch);

        $this->change(NetworkStatus::Offline, $switch, $fine, $broken);
        $this->change(NetworkStatus::Online, $switch, $fine);

        $this->assertSame(IncidentStatus::Resolved, Incident::where('network_device_id', $switch->id)->sole()->status);
        $own = Incident::where('network_device_id', $broken->id)->sole();
        $this->assertNull($own->resolved_at);
        $this->assertSame(0, Incident::where('network_device_id', $fine->id)->count());
    }

    // ------------------------------------------------- on call

    public function test_the_assistant_on_shift_in_the_room_is_told_first_then_the_engineers_then_the_administrators(): void
    {
        $here = $this->onShift($this->room);
        $elsewhere = $this->onShift(Location::factory()->create());
        $ap = $this->accessPointBehind();

        $this->change(NetworkStatus::Offline, $ap);

        $this->assertSame(1, $this->alarmsFor($here));
        $this->assertSame(0, $this->alarmsFor($elsewhere), 'An assistant in another room was told before the one in this one.');
        $this->assertSame(0, $this->alarmsFor($this->engineer));

        $this->travel((int) config('atlas.incidents.escalate_after_min') + 1)->minutes();
        $this->artisan('atlas:watch-incidents')->assertSuccessful();
        $this->assertSame(1, $this->alarmsFor($this->engineer));
        $this->assertSame(0, $this->alarmsFor($this->admin));

        $this->travel((int) config('atlas.incidents.escalate_after_min') + 1)->minutes();
        $this->artisan('atlas:watch-incidents')->assertSuccessful();
        $this->assertSame(1, $this->alarmsFor($this->admin));

        // Nobody left: the sweep stops asking.
        $this->assertNull(Incident::sole()->next_escalation_at);
    }

    public function test_an_assistant_who_forgot_to_check_out_is_not_on_call_all_evening(): void
    {
        $forgot = $this->onShift($this->room);
        // The factory's shift ends two hours after the frozen clock.
        $this->travel(120 + (int) config('atlas.shifts.on_shift_after_end_min') + 1)->minutes();

        $this->change(NetworkStatus::Offline, $this->accessPointBehind());

        $this->assertSame(0, $this->alarmsFor($forgot));
        $this->assertSame(1, $this->alarmsFor($this->engineer));
    }

    public function test_with_nobody_on_shift_the_engineers_are_told_at_once(): void
    {
        $this->change(NetworkStatus::Offline, $this->accessPointBehind());

        $this->assertSame(1, $this->alarmsFor($this->engineer));
        $this->assertSame(1, Incident::sole()->escalation_step);
    }

    public function test_acknowledging_stops_the_escalation(): void
    {
        $assistant = $this->onShift($this->room);
        $this->change(NetworkStatus::Offline, $this->accessPointBehind());
        $incident = Incident::sole();

        $this->actingAs($assistant)
            ->post(route('incidents.acknowledge', $incident))
            ->assertSessionHas('success');

        $this->travel(60)->minutes();
        $this->artisan('atlas:watch-incidents')->assertSuccessful();

        $incident->refresh();
        $this->assertSame(IncidentStatus::Acknowledged, $incident->status);
        $this->assertTrue($incident->acknowledgedBy->is($assistant));
        $this->assertSame(0, $this->alarmsFor($this->engineer));
    }

    public function test_only_people_whose_job_it_is_can_stop_the_escalation(): void
    {
        $alerted = $this->onShift($this->room);
        $offShift = $this->userWith(UserRole::Assistant);
        $this->change(NetworkStatus::Offline, $this->accessPointBehind());
        $incident = Incident::sole();

        $this->actingAs($offShift)->post(route('incidents.acknowledge', $incident))->assertForbidden();
        $this->assertSame(IncidentStatus::Open, $incident->fresh()->status);

        $this->actingAs($offShift)->get($incident->urlFor($offShift))->assertOk()->assertDontSee('Acknowledge');
        $this->actingAs($alerted)->get($incident->urlFor($alerted))->assertOk()->assertSee('Acknowledge');
        $this->actingAs($this->engineer)->post(route('incidents.acknowledge', $incident))->assertSessionHas('success');
    }

    public function test_a_late_event_about_a_status_the_device_has_left_opens_nothing(): void
    {
        $ap = $this->accessPointBehind();
        // The Offline event is retried after the device came back.
        event(DeviceStatusesChanged::fromTransitions([['device' => $ap, 'from' => NetworkStatus::Online, 'to' => NetworkStatus::Offline]]));

        $this->assertSame(0, Incident::count());
        $this->assertSame(0, Ticket::count());
    }

    public function test_an_engineer_can_close_a_degraded_incident_by_hand_and_not_an_outage(): void
    {
        $ap = $this->accessPointBehind();
        $this->change(NetworkStatus::Degraded, $ap);
        $degraded = Incident::sole();

        $this->actingAs($this->userWith(UserRole::Assistant))
            ->post(route('incidents.resolve', $degraded), ['note' => 'Exam week'])->assertForbidden();
        $this->actingAs($this->engineer)
            ->post(route('incidents.resolve', $degraded), ['note' => str_repeat('x', 501)])->assertSessionHasErrors('note');
        // The dialog opens again with the error, instead of hiding it.
        $this->actingAs($this->engineer)->withSession(['errors' => (new ViewErrorBag)->put('default', new MessageBag(['note' => ['Too long.']]))])
            ->get($degraded->urlFor($this->engineer))->assertSee('Too long.');
        $this->actingAs($this->engineer)
            ->post(route('incidents.resolve', $degraded), ['note' => 'Exam week, expected'])->assertSessionHas('success');
        $this->assertSame('by_hand', $degraded->fresh()->resolution);
        $this->assertTrue($degraded->events()->where('message', 'like', '%Exam week, expected%')->exists());

        $this->change(NetworkStatus::Offline, $ap);
        $outage = Incident::where('rule', IncidentRule::Offline)->sole();
        $this->actingAs($this->engineer)
            ->post(route('incidents.resolve', $outage), ['note' => 'x'])->assertForbidden();
        $this->assertNull($outage->fresh()->resolved_at);
    }

    // ------------------------------------------------- alerts once

    public function test_an_alert_is_sent_once_however_often_it_is_asked_for(): void
    {
        $ap = $this->accessPointBehind();
        $this->change(NetworkStatus::Offline, $ap);
        $incident = Incident::sole();
        $alerts = app(IncidentAlerts::class);

        $alerts->send($incident, collect([$this->engineer]), $incident->escalation_step);
        $alerts->deliver(IncidentAlert::sole()->id);

        $this->assertSame(1, IncidentAlert::count());
        $this->assertSame(1, $this->alarmsFor($this->engineer));
        $this->assertNotNull(IncidentAlert::sole()->sent_at);
    }

    public function test_email_and_telegram_alerts_go_out_when_they_are_set_up(): void
    {
        Mail::fake();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
        config(['atlas.mail_configured' => true, 'atlas.incidents.telegram_bot_token' => '123:abc']);
        $this->engineer->forceFill(['telegram_chat_id' => '4242'])->save();

        $this->change(NetworkStatus::Offline, $this->accessPointBehind());

        $channels = IncidentAlert::where('user_id', $this->engineer->id)->pluck('channel')->map->value->sort()->values()->all();
        $this->assertSame([AlertChannel::Email->value, AlertChannel::InApp->value, AlertChannel::Telegram->value], $channels);
        $this->assertSame(3, IncidentAlert::where('user_id', $this->engineer->id)->whereNotNull('sent_at')->count());
        Http::assertSent(fn ($request) => str_contains($request->url(), '/bot123:abc/sendMessage') && $request['chat_id'] === '4242');
    }

    public function test_a_failed_telegram_alert_is_retried_recorded_and_sent_nowhere_twice(): void
    {
        // The real queue and a worker, as the stack runs them. The test
        // queue runs jobs inline, where a failure surfaces as an exception.
        config(['queue.default' => 'database', 'atlas.incidents.telegram_bot_token' => '123:abc']);
        Http::fake(['api.telegram.org/*' => Http::response('down', 502)]);
        $this->engineer->forceFill(['telegram_chat_id' => '4242'])->save();

        $this->change(NetworkStatus::Offline, $this->accessPointBehind());
        $this->artisan('queue:work', ['--stop-when-empty' => true, '--tries' => 2, '--backoff' => 0]);

        $telegram = IncidentAlert::where('channel', AlertChannel::Telegram)->sole();
        $this->assertNull($telegram->sent_at);
        $this->assertNotNull($telegram->failed_at);
        Http::assertSentCount(2);
        // The in-app alert is a separate claim, and it went, once.
        $this->assertSame(1, $this->alarmsFor($this->engineer));
    }

    public function test_a_telegram_connection_error_is_stored_without_the_bot_token(): void
    {
        config(['queue.default' => 'database', 'atlas.incidents.telegram_bot_token' => '987654:SECRET-token-value']);
        Http::fake(fn () => throw new ConnectionException('cURL error 7: Failed to connect for https://api.telegram.org/bot987654:SECRET-token-value/sendMessage'));
        $this->engineer->forceFill(['telegram_chat_id' => '4242'])->save();

        $this->change(NetworkStatus::Offline, $this->accessPointBehind());
        $this->artisan('queue:work', ['--stop-when-empty' => true, '--tries' => 1]);

        $error = IncidentAlert::where('channel', AlertChannel::Telegram)->sole()->error;
        $this->assertStringContainsString('[token]', $error);
        $this->assertStringNotContainsString('SECRET', $error);
        $this->assertStringNotContainsString('SECRET', (string) DB::table('failed_jobs')->value('exception'));

        $this->actingAs($this->engineer)->get(Incident::sole()->urlFor($this->engineer))->assertOk()->assertSee('[token]');
    }

    // ------------------------------------------------- degraded, flapping, silent

    public function test_a_degraded_device_opens_an_incident_without_a_ticket(): void
    {
        $ap = $this->accessPointBehind();

        $this->change(NetworkStatus::Degraded, $ap);

        $incident = Incident::sole();
        $this->assertSame(IncidentRule::Degraded, $incident->rule);
        $this->assertNull($incident->ticket_id);
        $this->assertSame(1, Alarm::where('type', AlarmType::ApDegraded)->count());

        $this->change(NetworkStatus::Online, $ap);
        $this->assertTrue($incident->fresh()->isResolved());
    }

    public function test_an_engineer_can_raise_a_ticket_from_a_degraded_incident_and_an_assistant_cannot(): void
    {
        $this->change(NetworkStatus::Degraded, $this->accessPointBehind());
        $incident = Incident::sole();

        $this->actingAs($this->userWith(UserRole::Assistant))
            ->post(route('incidents.ticket', $incident))
            ->assertForbidden();

        $this->actingAs($this->engineer)
            ->post(route('incidents.ticket', $incident))
            ->assertSessionHas('success');

        $this->assertNotNull($incident->fresh()->ticket_id);
        $this->assertTrue(Ticket::sole()->reporter->is($this->engineer));
    }

    public function test_a_device_that_keeps_changing_status_is_one_flapping_incident(): void
    {
        $ap = $this->accessPointBehind();

        for ($i = 0; $i < (int) config('atlas.incidents.flapping_changes'); $i++) {
            $this->change($i % 2 === 0 ? NetworkStatus::Degraded : NetworkStatus::Online, $ap);
            $this->travel(1)->minutes();
        }

        $flapping = Incident::where('rule', IncidentRule::Flapping)->sole();
        $this->assertNull($flapping->resolved_at);

        // Settled for a whole window: the sweep closes it.
        $this->travel((int) config('atlas.incidents.flapping_window_min') + 1)->minutes();
        $this->artisan('atlas:watch-incidents')->assertSuccessful();
        $this->assertSame('settled', $flapping->fresh()->resolution);
    }

    public function test_a_silent_agent_is_an_incident_for_engineers_and_administrators_until_it_reports(): void
    {
        $assistant = $this->onShift($this->room);
        $agent = Agent::factory()->create();
        $agent->forceFill(['last_seen_at' => now()->subMinutes(10), 'silent_since' => now()])->save();

        event(new AgentSilenceChanged($agent->id, true));

        $incident = Incident::where('agent_id', $agent->id)->sole();
        $this->assertSame(1, $this->alarmsFor($this->engineer));
        $this->assertSame(1, $this->alarmsFor($this->admin));
        $this->assertSame(0, $this->alarmsFor($assistant));

        $agent->forceFill(['silent_since' => null])->save();
        event(new AgentSilenceChanged($agent->id, false));
        $this->assertTrue($incident->fresh()->isResolved());
    }

    // ------------------------------------------------- the sweep

    public function test_the_sweep_opens_and_resolves_what_lost_events_missed(): void
    {
        $ap = $this->accessPointBehind();
        Event::fake([DeviceStatusesChanged::class]);

        $this->change(NetworkStatus::Offline, $ap);
        $this->assertSame(0, Incident::count());

        $this->artisan('atlas:watch-incidents')->expectsOutputToContain('Opened 1')->assertSuccessful();
        $this->assertSame(1, Incident::unresolved()->count());

        $this->change(NetworkStatus::Online, $ap);
        $this->artisan('atlas:watch-incidents')->expectsOutputToContain('resolved 1')->assertSuccessful();
        $this->assertSame(0, Incident::unresolved()->count());
    }

    public function test_the_sweep_twice_changes_nothing_the_second_time(): void
    {
        $this->change(NetworkStatus::Offline, $this->switch(), $this->accessPointBehind());

        $before = [Incident::count(), Ticket::count(), Alarm::count(), DB::table('incident_devices')->count()];
        app(IncidentService::class)->sweep();
        app(IncidentService::class)->sweep();

        $this->assertSame($before, [Incident::count(), Ticket::count(), Alarm::count(), DB::table('incident_devices')->count()]);
    }

    // ------------------------------------------------- screens

    public function test_every_role_sees_the_incidents_screens_empty_and_populated(): void
    {
        $assistant = $this->userWith(UserRole::Assistant);

        foreach ([$this->admin, $this->engineer, $assistant] as $user) {
            $this->actingAs($user)->get(route($user->role->value.'.incidents.index'))->assertOk()->assertSee('Nothing wrong right now');
        }

        $switch = $this->switch();
        $this->change(NetworkStatus::Offline, $switch, $this->accessPointBehind($switch));
        $incident = Incident::sole();

        foreach ([$this->admin, $this->engineer, $assistant] as $user) {
            $this->actingAs($user)->get(route($user->role->value.'.incidents.index'))->assertOk()->assertSee($incident->title)->assertSee('2 devices down');
            $this->actingAs($user)->get($incident->urlFor($user))->assertOk()
                ->assertSee('Who was told')
                ->assertSee($incident->ticket->ticket_number);
        }

        $this->actingAs($this->engineer)->get(route('engineer.incidents.index', ['view' => 'resolved']))->assertOk()->assertSee('Nothing resolved yet');

        // The ticket leads back to its incident.
        $this->actingAs($this->engineer)->get(route('engineer.tickets.show', $incident->ticket))
            ->assertOk()->assertSee($incident->urlFor($this->engineer), false);

        // Each role's own screen only.
        $this->actingAs($assistant)->get(route('engineer.incidents.index'))->assertForbidden();
    }

    public function test_an_alert_opens_its_incident(): void
    {
        $this->change(NetworkStatus::Offline, $this->accessPointBehind());
        $alarm = Alarm::where('user_id', $this->engineer->id)->sole();

        $this->actingAs($this->engineer)
            ->get(route('notifications.show', $alarm))
            ->assertRedirect(route('engineer.incidents.show', Incident::sole()));
    }
}
