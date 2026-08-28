<?php

namespace Tests\Feature;

use App\Enums\ApStatus;
use App\Enums\DeviceStatus;
use App\Models\AccessPoint;
use App\Models\LabPc;
use App\Models\Location;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The four foundation tables every other module has a foreign key into.
 *
 * These pin the constraints that are enforced by the DATABASE rather than by a
 * form rule, because those are the ones nobody notices are missing until a
 * demo produces two machines with the same asset tag.
 *
 * @see docs/06-database.md — Conventions
 */
class FoundationSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_asset_tag_cannot_be_used_twice(): void
    {
        // The classic inventory-system demo failure.
        LabPc::factory()->create(['asset_tag' => 'APU-1042']);

        $this->expectException(QueryException::class);
        LabPc::factory()->create(['asset_tag' => 'APU-1042']);
    }

    public function test_a_mac_address_cannot_be_used_twice(): void
    {
        AccessPoint::factory()->create(['mac_address' => '00:1B:44:11:3A:B7']);

        $this->expectException(QueryException::class);
        AccessPoint::factory()->create(['mac_address' => '00:1B:44:11:3A:B7']);
    }

    public function test_a_location_holding_assets_cannot_be_deleted(): void
    {
        // restrictOnDelete: deleting a lab must not orphan the machines in it,
        // nor silently cascade them away with their inspection history.
        $location = Location::factory()->create();
        LabPc::factory()->create(['location_id' => $location->id]);

        $this->expectException(QueryException::class);
        $location->delete();
    }

    public function test_retiring_a_machine_keeps_its_record(): void
    {
        // Soft deletes exist so a retired machine's inspections and tickets
        // survive it leaving service (NFR-06).
        $pc = LabPc::factory()->create();
        $pc->delete();

        $this->assertSoftDeleted($pc);
        $this->assertNotNull(LabPc::withTrashed()->find($pc->id));
    }

    public function test_statuses_come_back_as_enums(): void
    {
        $pc = LabPc::factory()->create();
        $ap = AccessPoint::factory()->create();

        $this->assertInstanceOf(DeviceStatus::class, $pc->fresh()->status);
        $this->assertInstanceOf(ApStatus::class, $ap->fresh()->status);
    }

    public function test_system_written_columns_are_not_mass_assignable(): void
    {
        // A form must never be able to set a risk score or tag a record as
        // demo data. Those are written by M7's scoring run and the seeder.
        $pc = LabPc::factory()->create();

        $pc->fill(['risk_score' => 99, 'is_demo' => true]);

        $this->assertNull($pc->risk_score);
        $this->assertFalse((bool) $pc->is_demo);
    }

    public function test_an_access_point_under_maintenance_is_not_collectable(): void
    {
        // The collector skips it so nobody is paged while somebody is standing
        // on a ladder holding the device.
        AccessPoint::factory()->create();
        AccessPoint::factory()->maintenance()->create();

        $this->assertSame(2, AccessPoint::count());
        $this->assertSame(1, AccessPoint::collectable()->count());
    }

    public function test_a_retired_machine_drops_out_of_service(): void
    {
        LabPc::factory()->create();
        LabPc::factory()->retired()->create();

        $this->assertSame(2, LabPc::count());
        $this->assertSame(1, LabPc::inService()->count());
    }

    public function test_the_demo_factory_state_tags_rows_for_purging(): void
    {
        // Anything DemoDataSeeder creates must carry this flag, or
        // atlas:purge-demo-data cannot find it and it survives as fake data
        // pretending to be real.
        LabPc::factory()->demo()->create();
        LabPc::factory()->create();

        $this->assertSame(1, LabPc::demo()->count());
    }

    public function test_a_location_reports_the_assets_it_holds(): void
    {
        $location = Location::factory()->create();
        LabPc::factory()->count(3)->create(['location_id' => $location->id]);
        AccessPoint::factory()->count(2)->create(['location_id' => $location->id]);

        $this->assertCount(3, $location->labPcs);
        $this->assertCount(2, $location->accessPoints);
    }
}
