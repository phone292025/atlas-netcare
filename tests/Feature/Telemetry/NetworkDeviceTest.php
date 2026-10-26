<?php

namespace Tests\Feature\Telemetry;

use App\Enums\DeviceKind;
use App\Enums\TicketCategory;
use App\Enums\TicketPriority;
use App\Enums\UserRole;
use App\Models\AccessPoint;
use App\Models\Location;
use App\Models\NetworkDevice;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Access points as one kind of network device (ADR-0016).
 *
 * Switches, routers and access points share a table. What has to hold is that
 * code written for access points never sees a switch, and that an access
 * point found any other way still behaves as one.
 */
class NetworkDeviceTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_access_point_is_stored_as_that_kind(): void
    {
        $ap = AccessPoint::factory()->create();

        $this->assertSame(DeviceKind::AccessPoint, $ap->fresh()->kind);
        $this->assertDatabaseHas('network_devices', ['id' => $ap->id, 'kind' => 'access_point']);
    }

    public function test_access_point_queries_never_see_other_kinds(): void
    {
        $ap = AccessPoint::factory()->create();
        $switch = NetworkDevice::factory()->kind(DeviceKind::Switch)->create();

        $this->assertSame([$ap->id], AccessPoint::pluck('id')->all());
        $this->assertNull(AccessPoint::find($switch->id));
        $this->assertSame(1, $ap->location->accessPoints()->count());
    }

    public function test_an_access_point_found_through_the_base_class_is_still_an_access_point(): void
    {
        $ap = AccessPoint::factory()->create(['client_capacity' => 40]);
        $switch = NetworkDevice::factory()->kind(DeviceKind::Switch)->create();
        $ap->update(['parent_id' => $switch->id]);

        $loaded = NetworkDevice::find($ap->id);
        $this->assertInstanceOf(AccessPoint::class, $loaded);
        $this->assertSame(0.5, $loaded->saturation(20));

        $child = $switch->children()->first();
        $this->assertInstanceOf(AccessPoint::class, $child);

        $this->assertNotInstanceOf(AccessPoint::class, NetworkDevice::find($switch->id));
    }

    public function test_an_access_point_loaded_without_its_kind_column_is_still_an_access_point(): void
    {
        AccessPoint::factory()->create();

        // A query selecting a few columns leaves `kind` out.
        $this->assertInstanceOf(AccessPoint::class, AccessPoint::query()->select('id', 'code')->first());
    }

    public function test_every_kind_shares_one_set_of_codes(): void
    {
        AccessPoint::factory()->create(['code' => 'CORE-01']);

        $this->expectException(QueryException::class);
        NetworkDevice::factory()->kind(DeviceKind::Switch)->create(['code' => 'CORE-01']);
    }

    public function test_a_device_without_a_kind_is_refused(): void
    {
        // No default, so nothing quietly becomes an access point.
        $this->expectException(QueryException::class);
        NetworkDevice::query()->insert([
            'code' => 'NO-KIND',
            'location_id' => Location::factory()->create()->id,
            'status' => 'online',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_a_ticket_about_a_switch_finds_its_switch(): void
    {
        $switch = NetworkDevice::factory()->kind(DeviceKind::Switch)->create();
        $ticket = Ticket::factory()->create([
            'ticketable_type' => $switch->getMorphClass(),
            'ticketable_id' => $switch->id,
            'reported_by' => User::factory()->create()->id,
            'category' => TicketCategory::Network,
            'priority' => TicketPriority::Critical,
        ]);

        $this->assertTrue($ticket->fresh()->ticketable->is($switch));
        $this->assertSame([$ticket->id], $switch->tickets()->pluck('id')->all());
    }

    public function test_a_device_cannot_hang_from_itself_or_from_a_device_below_it(): void
    {
        $core = NetworkDevice::factory()->kind(DeviceKind::Switch)->create();
        $edge = NetworkDevice::factory()->kind(DeviceKind::Switch)->create(['parent_id' => $core->id]);
        $ap = AccessPoint::factory()->create(['parent_id' => $edge->id]);
        $elsewhere = NetworkDevice::factory()->kind(DeviceKind::Router)->create();

        $this->assertTrue($core->isSelfOrAncestorOf($core));
        $this->assertTrue($core->isSelfOrAncestorOf($ap));
        $this->assertFalse($ap->isSelfOrAncestorOf($core));
        $this->assertFalse($core->isSelfOrAncestorOf($elsewhere));
    }

    public function test_removing_a_parent_for_good_leaves_its_children_unattached(): void
    {
        $switch = NetworkDevice::factory()->kind(DeviceKind::Switch)->create();
        $ap = AccessPoint::factory()->create(['parent_id' => $switch->id]);

        $switch->forceDelete();

        $this->assertNull($ap->fresh()->parent_id);
    }

    public function test_a_location_with_only_a_switch_in_it_cannot_be_deleted(): void
    {
        $location = Location::factory()->create();
        NetworkDevice::factory()->kind(DeviceKind::Switch)->create(['location_id' => $location->id]);

        $admin = User::factory()->create();
        $admin->forceFill(['role' => UserRole::Admin, 'is_active' => true])->save();

        $this->actingAs($admin)
            ->delete(route('admin.locations.destroy', $location))
            ->assertSessionHas('error');

        $this->assertModelExists($location);
    }
}
