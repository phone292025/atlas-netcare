<?php

namespace Tests\Feature\Admin;

use App\Enums\DeviceKind;
use App\Enums\NetworkStatus;
use App\Enums\UserRole;
use App\Models\AccessPoint;
use App\Models\Location;
use App\Models\NetworkDevice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The network devices inventory: switches, routers and servers, and what
 * each one is connected to (ADR-0016).
 *
 * Most of these are about the parent link. Phase 2 groups faults by it, so a
 * loop, a parent nobody can see, or an access point as a parent would each
 * leave an incident with no device to open on.
 */
class NetworkDeviceInventoryTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->forceFill(['role' => UserRole::Admin, 'is_active' => true])->save();

        return $user->fresh();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'kind' => DeviceKind::Switch->value,
            'code' => 'sw-l6-01',
            'name' => 'Level 6 core switch',
            'location_id' => Location::factory()->create()->id,
            'ip_address' => '10.20.6.1',
            'status' => NetworkStatus::Online->value,
        ], $overrides);
    }

    private function switch(array $attributes = []): NetworkDevice
    {
        return NetworkDevice::factory()->kind(DeviceKind::Switch)->create($attributes);
    }

    public function test_the_list_shows_switches_and_routers_but_not_access_points(): void
    {
        $switch = $this->switch(['code' => 'SW-CORE']);
        $ap = AccessPoint::factory()->create(['code' => 'AP-HIDDEN', 'parent_id' => $switch->id]);

        $this->actingAs($this->admin())
            ->get(route('admin.devices.index'))
            ->assertOk()
            ->assertSee('SW-CORE')
            ->assertDontSee('AP-HIDDEN');
    }

    public function test_the_empty_list_says_what_to_add(): void
    {
        $this->actingAs($this->admin())
            ->get(route('admin.devices.index'))
            ->assertOk()
            ->assertSee('No network devices yet');
    }

    public function test_an_admin_adds_a_switch(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.devices.store'), $this->validPayload())
            ->assertSessionHasNoErrors();

        $switch = NetworkDevice::firstWhere('code', 'SW-L6-01');
        $this->assertNotNull($switch, 'The code is stored uppercase.');
        $this->assertSame(DeviceKind::Switch, $switch->kind);
        $this->assertNull($switch->mac_address);
    }

    public function test_access_point_is_not_a_kind_this_form_adds(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.devices.store'), $this->validPayload(['kind' => DeviceKind::AccessPoint->value]))
            ->assertSessionHasErrors('kind');

        $this->assertDatabaseCount('network_devices', 0);
    }

    public function test_a_device_cannot_be_connected_to_itself(): void
    {
        $switch = $this->switch();

        $this->actingAs($this->admin())
            ->patch(route('admin.devices.update', $switch), $this->validPayload([
                'code' => $switch->code,
                'parent_id' => $switch->id,
            ]))
            ->assertSessionHasErrors(['parent_id' => 'A device can\'t be connected to itself.']);
    }

    public function test_a_device_cannot_be_connected_below_itself(): void
    {
        $core = $this->switch(['code' => 'SW-CORE']);
        $edge = $this->switch(['code' => 'SW-EDGE', 'parent_id' => $core->id]);

        $this->actingAs($this->admin())
            ->patch(route('admin.devices.update', $core), $this->validPayload([
                'code' => 'SW-CORE',
                'parent_id' => $edge->id,
            ]))
            ->assertSessionHasErrors(['parent_id' => 'SW-EDGE is connected below SW-CORE, so it can\'t be its parent.']);

        $this->assertNull($core->fresh()->parent_id);
    }

    public function test_the_form_offers_no_parent_that_would_close_a_loop(): void
    {
        $core = $this->switch(['code' => 'SW-CORE']);
        $this->switch(['code' => 'SW-EDGE', 'parent_id' => $core->id]);
        $this->switch(['code' => 'SW-OTHER']);

        $this->assertSame(['SW-OTHER'], array_map(
            fn (string $label) => strtok($label, ' '),
            array_values(NetworkDevice::parentChoices($core)),
        ));
    }

    public function test_an_access_point_cannot_be_a_parent(): void
    {
        $ap = AccessPoint::factory()->create();

        $this->actingAs($this->admin())
            ->post(route('admin.devices.store'), $this->validPayload(['parent_id' => $ap->id]))
            ->assertSessionHasErrors('parent_id');
    }

    public function test_a_retired_device_cannot_be_a_parent(): void
    {
        $retired = $this->switch();
        $retired->delete();

        $this->actingAs($this->admin())
            ->post(route('admin.devices.store'), $this->validPayload(['parent_id' => $retired->id]))
            ->assertSessionHasErrors('parent_id');
    }

    public function test_a_device_with_others_connected_to_it_is_not_retired(): void
    {
        $switch = $this->switch(['code' => 'SW-CORE']);
        AccessPoint::factory()->create(['code' => 'AP-01', 'parent_id' => $switch->id]);

        $this->actingAs($this->admin())
            ->delete(route('admin.devices.destroy', $switch))
            ->assertSessionHas('error', 'SW-CORE still has AP-01 connected to it. Connect them to another device first.');

        $this->assertNotSoftDeleted($switch);
    }

    public function test_a_retired_device_keeps_its_page_and_comes_back(): void
    {
        $switch = $this->switch(['code' => 'SW-SPARE']);
        $admin = $this->admin();

        $this->actingAs($admin)->delete(route('admin.devices.destroy', $switch))->assertRedirect(route('admin.devices.index'));
        $this->assertSoftDeleted($switch);

        $this->actingAs($admin)->get(route('admin.devices.show', $switch))->assertOk()->assertSee('retired');

        $this->actingAs($admin)->patch(route('admin.devices.restore', $switch));
        $this->assertNotSoftDeleted($switch);
    }

    public function test_the_device_page_lists_what_is_connected_below_it(): void
    {
        $switch = $this->switch(['code' => 'SW-CORE']);
        $ap = AccessPoint::factory()->create(['code' => 'AP-01', 'parent_id' => $switch->id]);

        $this->actingAs($this->admin())
            ->get(route('admin.devices.show', $switch))
            ->assertOk()
            ->assertSee('AP-01')
            ->assertSee(route('admin.aps.show', $ap), escape: false);
    }

    public function test_an_access_point_is_not_edited_through_this_screen(): void
    {
        $ap = AccessPoint::factory()->create();

        $this->actingAs($this->admin())->get(route('admin.devices.show', $ap))->assertNotFound();
        $this->actingAs($this->admin())->get(route('admin.devices.edit', $ap))->assertNotFound();
    }

    public function test_an_access_point_is_connected_to_a_switch_from_its_own_form(): void
    {
        $switch = $this->switch(['code' => 'SW-CORE', 'name' => 'Core']);
        $ap = AccessPoint::factory()->create();

        $this->actingAs($this->admin())
            ->get(route('admin.aps.edit', $ap))
            ->assertOk()
            ->assertSee('SW-CORE · Core');

        $this->actingAs($this->admin())
            ->patch(route('admin.aps.update', $ap), [
                'code' => $ap->code,
                'location_id' => $ap->location_id,
                'mac_address' => $ap->mac_address,
                'client_capacity' => 50,
                'status' => NetworkStatus::Online->value,
                'parent_id' => $switch->id,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame($switch->id, $ap->fresh()->parent_id);
    }
}
