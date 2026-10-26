<?php

namespace Tests\Feature\Admin;

use App\Enums\NetworkStatus;
use App\Enums\UserRole;
use App\Models\AccessPoint;
use App\Models\Location;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * M2 — access point inventory.
 *
 * A MAC address identifies one piece of hardware. Two records claiming the
 * same one means the dashboard is averaging two halves of the same access
 * point, which is the classic inventory demo failure — so most of these tests
 * are about the ways a duplicate could slip past.
 *
 * @see docs/modules/M2-locations-and-assets.md
 */
class AccessPointInventoryTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return $this->userWith(UserRole::Admin);
    }

    private function userWith(UserRole $role): User
    {
        $user = User::factory()->create();
        $user->forceFill(['role' => $role, 'is_active' => true])->save();

        return $user->fresh();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'code' => 'AP-L6-03',
            'location_id' => Location::factory()->create()->id,
            'model' => 'Cisco C9120AXI',
            'mac_address' => '00:1B:44:11:3A:B7',
            'ip_address' => '10.20.6.31',
            'client_capacity' => 50,
            'installed_at' => '2024-03-01',
            'status' => NetworkStatus::Online->value,
        ], $overrides);
    }

    // ------------------------------------------------------------ access

    public function test_an_assistant_cannot_reach_the_inventory(): void
    {
        $this->actingAs($this->userWith(UserRole::Assistant))
            ->get(route('admin.aps.index'))
            ->assertForbidden();
    }

    public function test_an_engineer_cannot_add_an_access_point(): void
    {
        // Engineers read AP status through M5. Registering hardware is an
        // administrative act, and the inventory is the system of record.
        $this->actingAs($this->userWith(UserRole::Engineer))
            ->post(route('admin.aps.store'), $this->validPayload())
            ->assertForbidden();

        $this->assertDatabaseCount('network_devices', 0);
    }

    // ------------------------------------------------------------ create

    public function test_an_admin_can_add_an_access_point(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.aps.store'), $this->validPayload())
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('network_devices', [
            'code' => 'AP-L6-03',
            'mac_address' => '00:1B:44:11:3A:B7',
        ]);
    }

    /**
     * Every spelling of one MAC has to land on the same stored value, or the
     * unique index is decorative: it would happily accept the same device
     * three times as long as somebody typed the separators differently.
     */
    public function test_a_mac_address_is_stored_in_one_canonical_form(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.aps.store'), $this->validPayload([
                'mac_address' => '00-1b-44-11-3a-b7',
            ]))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('network_devices', ['mac_address' => '00:1B:44:11:3A:B7']);
    }

    public function test_a_duplicate_mac_address_is_rejected_however_it_is_written(): void
    {
        AccessPoint::factory()->create(['mac_address' => '00:1B:44:11:3A:B7']);

        $this->actingAs($this->admin())
            ->post(route('admin.aps.store'), $this->validPayload([
                'code' => 'AP-L6-04',
                'mac_address' => '00-1b-44-11-3a-b7',
            ]))
            ->assertSessionHasErrors('mac_address');

        $this->assertDatabaseCount('network_devices', 1);
    }

    public function test_a_malformed_mac_address_is_rejected(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.aps.store'), $this->validPayload(['mac_address' => 'not-a-mac']))
            ->assertSessionHasErrors('mac_address');
    }

    public function test_a_duplicate_code_is_rejected(): void
    {
        AccessPoint::factory()->create(['code' => 'AP-L6-03']);

        $this->actingAs($this->admin())
            ->post(route('admin.aps.store'), $this->validPayload([
                'mac_address' => '00:1B:44:11:3A:C9',
            ]))
            ->assertSessionHasErrors('code');
    }

    public function test_client_capacity_is_required(): void
    {
        // It is the denominator of the saturation threshold M5 alarms on. An
        // access point without one can never be judged crowded.
        $this->actingAs($this->admin())
            ->post(route('admin.aps.store'), $this->validPayload(['client_capacity' => null]))
            ->assertSessionHasErrors('client_capacity');
    }

    // ------------------------------------------------------------ edit

    public function test_an_admin_can_put_an_access_point_into_maintenance(): void
    {
        $ap = AccessPoint::factory()->create();

        $this->actingAs($this->admin())
            ->patch(route('admin.aps.update', $ap), $this->validPayload([
                'code' => $ap->code,
                'location_id' => $ap->location_id,
                'mac_address' => $ap->mac_address,
                'status' => NetworkStatus::Maintenance->value,
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame(NetworkStatus::Maintenance, $ap->fresh()->status);
    }

    public function test_the_form_cannot_write_the_last_seen_timestamp(): void
    {
        // last_seen_at is the collector's. If a form could set it, an offline
        // access point could be made to look alive from the browser.
        $ap = AccessPoint::factory()->create();

        $this->actingAs($this->admin())
            ->patch(route('admin.aps.update', $ap), $this->validPayload([
                'code' => $ap->code,
                'location_id' => $ap->location_id,
                'mac_address' => $ap->mac_address,
                'last_seen_at' => now()->toDateTimeString(),
            ]));

        $this->assertNull($ap->fresh()->last_seen_at);
    }

    // ------------------------------------------------------------ remove

    public function test_removing_an_access_point_keeps_the_record(): void
    {
        $ap = AccessPoint::factory()->create();

        $this->actingAs($this->admin())
            ->delete(route('admin.aps.destroy', $ap))
            ->assertRedirect(route('admin.aps.index'));

        $this->assertSoftDeleted('network_devices', ['id' => $ap->id]);
    }

    public function test_a_removed_access_point_drops_out_of_the_default_list(): void
    {
        $kept = AccessPoint::factory()->create(['code' => 'AP-L6-01']);
        $gone = AccessPoint::factory()->create(['code' => 'AP-L6-02']);
        $gone->delete();

        $this->actingAs($this->admin())
            ->get(route('admin.aps.index'))
            ->assertOk()
            ->assertSee($kept->code)
            ->assertDontSee($gone->code);
    }

    public function test_a_removed_access_point_is_listed_when_asked_for(): void
    {
        $gone = AccessPoint::factory()->create(['code' => 'AP-L6-02']);
        $gone->delete();

        $this->actingAs($this->admin())
            ->get(route('admin.aps.index', ['removed' => 1]))
            ->assertOk()
            ->assertSee($gone->code);
    }

    public function test_a_removed_access_point_keeps_its_history_reachable(): void
    {
        $ap = AccessPoint::factory()->create();
        $ap->delete();

        $this->actingAs($this->admin())
            ->get(route('admin.aps.show', $ap))
            ->assertOk()
            ->assertSee($ap->code);
    }

    public function test_a_removed_access_point_can_be_restored(): void
    {
        $ap = AccessPoint::factory()->create();
        $ap->delete();

        $this->actingAs($this->admin())
            ->patch(route('admin.aps.restore', $ap))
            ->assertRedirect(route('admin.aps.show', $ap));

        $this->assertNotSoftDeleted('network_devices', ['id' => $ap->id]);
    }
}
