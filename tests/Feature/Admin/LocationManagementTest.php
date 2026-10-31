<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Models\AccessPoint;
use App\Models\LabPc;
use App\Models\Location;
use App\Models\ShiftTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * M2 — locations.
 *
 * Locations are reference data every other module points at, so the things
 * worth pinning are the ones that would corrupt that reference: a duplicate
 * code, or a location vanishing out from under the assets that name it.
 *
 * @see docs/modules/M2-locations-and-assets.md
 */
class LocationManagementTest extends TestCase
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

    /** @return array<string, string> */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'code' => 'L6-IT-09',
            'name' => 'Level 6 IT Lab 09',
            'type' => 'it_lab',
            'floor' => 'Level 6',
            'description' => null,
        ], $overrides);
    }

    // ------------------------------------------------------------ access

    public function test_an_engineer_cannot_reach_locations(): void
    {
        $this->actingAs($this->userWith(UserRole::Engineer))
            ->get(route('admin.locations.index'))
            ->assertForbidden();
    }

    public function test_an_assistant_cannot_create_a_location(): void
    {
        $this->actingAs($this->userWith(UserRole::Assistant))
            ->post(route('admin.locations.store'), $this->validPayload())
            ->assertForbidden();

        $this->assertDatabaseCount('locations', 0);
    }

    // ------------------------------------------------------------ create

    public function test_an_admin_can_create_a_location(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.locations.store'), $this->validPayload())
            ->assertRedirect(route('admin.locations.index'));

        $this->assertDatabaseHas('locations', ['code' => 'L6-IT-09', 'type' => 'it_lab']);
    }

    public function test_a_lowercase_code_is_stored_uppercase(): void
    {
        // Codes are read off door signage and typed in a hurry. Without this,
        // "l6-it-09" and "L6-IT-09" are two locations holding half the lab each.
        $this->actingAs($this->admin())
            ->post(route('admin.locations.store'), $this->validPayload(['code' => 'l6-it-09']))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('locations', ['code' => 'L6-IT-09']);
    }

    public function test_a_duplicate_code_is_rejected_whatever_its_case(): void
    {
        Location::factory()->create(['code' => 'L6-IT-09']);

        $this->actingAs($this->admin())
            ->post(route('admin.locations.store'), $this->validPayload(['code' => 'l6-it-09']))
            ->assertSessionHasErrors('code');

        $this->assertDatabaseCount('locations', 1);
    }

    public function test_editing_a_location_does_not_collide_with_its_own_code(): void
    {
        $location = Location::factory()->create(['code' => 'L6-IT-09']);

        $this->actingAs($this->admin())
            ->patch(route('admin.locations.update', $location), $this->validPayload(['name' => 'Renamed lab']))
            ->assertSessionHasNoErrors();

        $this->assertSame('Renamed lab', $location->fresh()->name);
    }

    // ------------------------------------------------------------ delete

    public function test_a_location_holding_assets_cannot_be_deleted(): void
    {
        $location = Location::factory()->create();
        LabPc::factory()->for($location)->create();

        $this->actingAs($this->admin())
            ->delete(route('admin.locations.destroy', $location))
            ->assertSessionHas('error');

        $this->assertDatabaseHas('locations', ['id' => $location->id]);
    }

    public function test_a_location_holding_only_retired_assets_still_cannot_be_deleted(): void
    {
        /*
         * The regression this exists for: the soft-delete scope hides a retired
         * machine from the relation, so a naive count reports the location
         * empty — and the delete then fails at the foreign key with a 500
         * instead of the message written for exactly this case.
         */
        $location = Location::factory()->create();
        $pc = LabPc::factory()->for($location)->create();
        $pc->delete();

        $this->actingAs($this->admin())
            ->delete(route('admin.locations.destroy', $location))
            ->assertSessionHas('error');

        $this->assertDatabaseHas('locations', ['id' => $location->id]);
    }

    public function test_a_location_on_the_roster_cannot_be_deleted(): void
    {
        // Shift patterns and shifts hold it by a restricting foreign key too.
        $location = Location::factory()->create();
        ShiftTemplate::factory()->create(['location_id' => $location->id]);

        $this->actingAs($this->admin())
            ->delete(route('admin.locations.destroy', $location))
            ->assertSessionHas('error');

        $this->assertDatabaseHas('locations', ['id' => $location->id]);
    }

    public function test_an_empty_location_can_be_deleted(): void
    {
        $location = Location::factory()->create();

        $this->actingAs($this->admin())
            ->delete(route('admin.locations.destroy', $location))
            ->assertRedirect(route('admin.locations.index'));

        $this->assertDatabaseMissing('locations', ['id' => $location->id]);
    }

    public function test_a_location_holding_an_access_point_cannot_be_deleted(): void
    {
        $location = Location::factory()->create();
        AccessPoint::factory()->for($location)->create();

        $this->actingAs($this->admin())
            ->delete(route('admin.locations.destroy', $location))
            ->assertSessionHas('error');

        $this->assertDatabaseHas('locations', ['id' => $location->id]);
    }
}
