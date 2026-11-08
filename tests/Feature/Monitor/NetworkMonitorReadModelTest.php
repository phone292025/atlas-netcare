<?php

namespace Tests\Feature\Monitor;

use App\Enums\DeviceKind;
use App\Enums\NetworkStatus;
use App\Enums\UserRole;
use App\Events\DeviceStatusesChanged;
use App\Models\AccessPoint;
use App\Models\DeviceStatusChange;
use App\Models\Incident;
use App\Models\Location;
use App\Models\NetworkDevice;
use App\Models\TelemetryReading;
use App\Models\User;
use App\Services\Incidents\IncidentService;
use App\Services\Monitor\NetworkMonitorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What the rebuilt Network monitor reads: every kind of device by place,
 * worst first, the incidents they are in and who has them, how long each has
 * been in its state, and where on its path a fault is.
 *
 * Status changes go through DeviceStatusesChanged, the way Telemetry
 * publishes them, so the incidents and the status history are the real ones.
 */
class NetworkMonitorReadModelTest extends TestCase
{
    use RefreshDatabase;

    private Location $room;

    private NetworkDevice $router;

    private NetworkDevice $switch;

    private User $engineer;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = $this->userWith(UserRole::Admin);
        $this->engineer = $this->userWith(UserRole::Engineer);
        $this->room = Location::factory()->create(['name' => 'Level 6 IT Lab 01']);
        $this->router = NetworkDevice::factory()->kind(DeviceKind::Router)->create(['code' => 'RT-CORE', 'location_id' => $this->room->id]);
        $this->switch = NetworkDevice::factory()->kind(DeviceKind::Switch)->create(['code' => 'SW-L6', 'location_id' => $this->room->id, 'parent_id' => $this->router->id]);
        $this->answering($this->router, $this->switch);
    }

    private function userWith(UserRole $role): User
    {
        $user = User::factory()->create();
        $user->forceFill(['role' => $role, 'is_active' => true])->save();

        return $user->fresh();
    }

    private function accessPoint(string $code): AccessPoint
    {
        $ap = AccessPoint::factory()->create(['code' => $code, 'location_id' => $this->room->id, 'parent_id' => $this->switch->id, 'client_capacity' => 40]);
        $this->answering($ap);

        return $ap;
    }

    /** A reading a minute old, so the monitor shows the device as it is. */
    private function answering(NetworkDevice ...$devices): void
    {
        foreach ($devices as $device) {
            TelemetryReading::factory()->create(['network_device_id' => $device->id, 'recorded_at' => now()->subMinute(), 'latency_ms' => 6, 'client_count' => $device->kind === DeviceKind::AccessPoint ? 12 : null]);
        }
    }

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

    /** @return array<string, mixed> */
    private function snapshot(?User $viewer = null): array
    {
        return app(NetworkMonitorService::class)->snapshot($viewer ?? $this->engineer);
    }

    public function test_every_kind_of_device_is_on_the_monitor_in_its_place_upstream_first(): void
    {
        $this->accessPoint('AP-06');

        $data = $this->snapshot();

        $this->assertEqualsCanonicalizing(['RT-CORE', 'SW-L6', 'AP-06'], array_column($data['devices'], 'code'));
        $place = collect($data['places'])->firstWhere('name', 'Level 6 IT Lab 01');
        $codes = collect($place['device_ids'])->map(fn ($id) => collect($data['devices'])->firstWhere('id', $id)['code'])->all();
        $this->assertSame(['RT-CORE', 'SW-L6', 'AP-06'], $codes, 'What the access points hang from comes first.');
        $this->assertSame(
            [['Router', 1, 1], ['Switch', 1, 1], ['Access point', 1, 1]],
            array_map(fn ($k) => [$k['label'], $k['up'], $k['total']], $data['kinds']),
        );
    }

    public function test_problems_come_first_and_the_place_they_are_in_comes_first(): void
    {
        $this->accessPoint('AP-01');
        $down = $this->accessPoint('AP-02');
        $calm = Location::factory()->create(['name' => 'A calm room']);
        NetworkDevice::factory()->kind(DeviceKind::Switch)->create(['code' => 'SW-CALM', 'location_id' => $calm->id]);
        $this->change(NetworkStatus::Offline, $down);

        $data = $this->snapshot();

        $this->assertSame('AP-02', $data['devices'][0]['code']);
        $this->assertSame('offline', $data['devices'][0]['state']);
        $this->assertSame('Level 6 IT Lab 01', $data['places'][0]['name'], 'A room with a fault comes before one in alphabetical order.');
    }

    public function test_an_incident_says_who_was_told_its_ticket_and_whether_this_person_can_take_it(): void
    {
        $ap = $this->accessPoint('AP-06');
        $this->change(NetworkStatus::Offline, $ap);
        $assistant = $this->userWith(UserRole::Assistant);

        $incident = $this->snapshot()['incidents'][0];
        $this->assertSame('AP-06', $incident['subject']);
        $this->assertTrue($incident['down']);
        $this->assertSame('Level 6 IT Lab 01', $incident['place']);
        $this->assertNotEmpty($incident['ticket_number']);
        $this->assertArrayNotHasKey('acknowledged_by', $incident);
        $this->assertStringContainsString($this->engineer->name, $incident['told']);
        $this->assertTrue($incident['can_acknowledge']);
        $this->assertFalse($this->snapshot($assistant)['incidents'][0]['can_acknowledge'], 'An assistant who was not told may not take it.');

        app(IncidentService::class)->acknowledge(Incident::sole(), $this->engineer);

        $incident = $this->snapshot()['incidents'][0];
        $this->assertSame($this->engineer->name, $incident['acknowledged_by']);
        $this->assertFalse($incident['can_acknowledge'], 'Taken already.');
        $this->assertSame(Incident::sole()->id, collect($this->snapshot()['devices'])->firstWhere('code', 'AP-06')['incident_id']);
    }

    public function test_an_incident_nobody_has_taken_comes_before_one_somebody_has(): void
    {
        $first = $this->accessPoint('AP-01');
        $second = $this->accessPoint('AP-02');
        $this->change(NetworkStatus::Offline, $first);
        $this->travel(5)->minutes();
        $this->change(NetworkStatus::Offline, $second);
        app(IncidentService::class)->acknowledge(Incident::query()->where('network_device_id', $first->id)->sole(), $this->engineer);

        $data = $this->snapshot();
        $this->assertSame(['AP-02', 'AP-01'], array_column($data['incidents'], 'subject'));
        $this->assertSame([2, 1, 2], [$data['incidents_open'], $data['incidents_untaken'], $data['incidents_down']]);
    }

    public function test_an_access_point_behind_a_failed_switch_is_in_the_switchs_incident(): void
    {
        $ap = $this->accessPoint('AP-06');
        $this->change(NetworkStatus::Offline, $this->switch, $ap);

        $data = $this->snapshot();

        $this->assertCount(1, $data['incidents']);
        $this->assertSame('SW-L6', $data['incidents'][0]['subject']);
        $this->assertSame(1, $data['incidents'][0]['behind']);
        $this->assertSame($data['incidents'][0]['id'], collect($data['devices'])->firstWhere('code', 'AP-06')['incident_id']);
    }

    public function test_a_device_says_how_long_it_has_been_in_its_state(): void
    {
        $ap = $this->accessPoint('AP-06');
        $this->change(NetworkStatus::Offline, $ap);
        $this->travel(23)->minutes();

        $row = collect($this->snapshot()['devices'])->firstWhere('code', 'AP-06');

        $this->assertSame('23 min', $row['for']);
        $this->assertNull(collect($this->snapshot()['devices'])->firstWhere('code', 'SW-L6')['for'], 'Online needs no clock.');
    }

    public function test_the_panel_says_the_fault_is_the_access_point_when_its_switch_answers(): void
    {
        $ap = $this->accessPoint('AP-06');
        $this->change(NetworkStatus::Offline, $ap);

        $device = $this->actingAs($this->engineer)->getJson(route('api.monitor.devices.show', $ap))->assertOk()->json();

        $this->assertSame(['RT-CORE', 'SW-L6'], array_column($device['path'], 'code'));
        $this->assertStringContainsString('switch SW-L6 answers, so the fault is at AP-06 itself', $device['verdict']);
        $this->assertSame(Incident::sole()->title, $device['incident']['title']);
        $this->assertNotEmpty($device['incident']['events']);
    }

    public function test_the_panel_sends_you_upstream_when_the_switch_is_down_too(): void
    {
        $ap = $this->accessPoint('AP-06');
        $this->change(NetworkStatus::Offline, $this->switch, $ap);

        $device = app(NetworkMonitorService::class)->device($ap->fresh(), $this->engineer);

        $this->assertStringStartsWith('SW-L6 is down as well, so start there', $device['verdict']);
    }

    public function test_the_panel_says_why_a_device_is_degraded(): void
    {
        $ap = $this->accessPoint('AP-09');
        TelemetryReading::factory()->create(['network_device_id' => $ap->id, 'recorded_at' => now()->subSeconds(10), 'latency_ms' => 180, 'client_count' => 20]);
        $ap->forceFill(['status' => NetworkStatus::Degraded])->saveQuietly();

        $device = app(NetworkMonitorService::class)->device($ap->fresh(), $this->engineer);

        $this->assertSame('It answers, but slowly: 180 ms against the '.config('atlas.thresholds.latency_ms').' ms limit.', $device['verdict']);
    }

    public function test_only_administrators_see_a_switchs_address_and_agent(): void
    {
        $this->switch->forceFill(['ip_address' => '10.20.6.2'])->save();
        $ap = $this->accessPoint('AP-06');
        $ap->forceFill(['ip_address' => '10.20.6.16'])->save();
        $panel = fn (User $viewer, NetworkDevice $device) => app(NetworkMonitorService::class)->device($device->fresh(), $viewer);

        foreach ([$this->engineer, $this->userWith(UserRole::Assistant)] as $viewer) {
            $this->assertNull($panel($viewer, $this->switch)['ip_address'], 'The devices screen is the administrators\'.');
            $this->assertNull($panel($viewer, $this->switch)['agent']);
            $this->assertSame('10.20.6.16', $panel($viewer, $ap)['ip_address'], 'An access point\'s address was always shown.');
        }

        $this->assertSame('10.20.6.2', $panel($this->userWith(UserRole::Admin), $this->switch)['ip_address']);
    }

    public function test_an_incident_on_a_removed_device_opens_its_page_not_a_panel(): void
    {
        $ap = $this->accessPoint('AP-06');
        $this->change(NetworkStatus::Offline, $ap);
        $ap->delete();

        $incident = $this->snapshot()['incidents'][0];

        $this->assertSame('AP-06', $incident['subject']);
        $this->assertArrayNotHasKey('device_id', $incident, 'There is no panel for a removed device.');
    }

    public function test_a_device_down_longer_than_the_status_history_still_says_how_long(): void
    {
        $ap = $this->accessPoint('AP-06');
        $this->change(NetworkStatus::Offline, $ap);
        $this->travel(9)->days();
        DeviceStatusChange::query()->delete();

        $this->assertSame('9 days', collect($this->snapshot()['devices'])->firstWhere('code', 'AP-06')['for']);
    }

    public function test_a_switch_opens_in_the_panel_too(): void
    {
        $this->actingAs($this->userWith(UserRole::Assistant))
            ->getJson(route('api.monitor.devices.show', $this->switch))
            ->assertOk()
            ->assertJsonPath('code', 'SW-L6')
            ->assertJsonPath('kind_label', 'Switch')
            ->assertJsonPath('path.0.code', 'RT-CORE')
            ->assertJsonPath('verdict', 'It and everything upstream of it answer.');
    }

    public function test_the_page_carries_the_open_incident(): void
    {
        $ap = $this->accessPoint('AP-06');
        $this->change(NetworkStatus::Offline, $ap);

        $this->actingAs($this->engineer)
            ->get(route('engineer.monitor.index'))
            ->assertOk()
            ->assertSee('Needs attention')
            // In the page's data, which Alpine renders; whether this person
            // may take it is the snapshot test's.
            ->assertSee(Incident::sole()->title);
    }
}
