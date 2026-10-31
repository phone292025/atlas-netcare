<?php

namespace Tests\Feature\Admin;

use App\Enums\DeviceStatus;
use App\Enums\UserRole;
use App\Models\LabPc;
use App\Models\Location;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * M2 — lab PC inventory.
 *
 * The asset tag is the link between a sticker on a case and years of
 * inspection history, and retirement is the one operation that must never lose
 * that history. Those are what these tests hold down.
 *
 * @see docs/modules/M2-locations-and-assets.md
 */
class LabPcInventoryTest extends TestCase
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
            'asset_tag' => 'APU-1042',
            'location_id' => Location::factory()->create()->id,
            'hostname' => 'apu-pc-042',
            'cpu' => 'Intel i5-10500',
            'ram_gb' => 16,
            'storage' => '512GB SSD',
            'os_version' => 'Windows 11 23H2',
            'purchase_date' => '2023-01-15',
            'warranty_expiry' => '2026-01-15',
            'status' => DeviceStatus::Operational->value,
        ], $overrides);
    }

    // ------------------------------------------------------------ access

    public function test_an_assistant_cannot_reach_the_inventory(): void
    {
        $this->actingAs($this->userWith(UserRole::Assistant))
            ->get(route('admin.lab-pcs.index'))
            ->assertForbidden();
    }

    public function test_an_engineer_cannot_add_a_machine(): void
    {
        $this->actingAs($this->userWith(UserRole::Engineer))
            ->post(route('admin.lab-pcs.store'), $this->validPayload())
            ->assertForbidden();

        $this->assertDatabaseCount('lab_pcs', 0);
    }

    // ------------------------------------------------------------ create

    public function test_an_admin_can_add_a_machine(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.lab-pcs.store'), $this->validPayload())
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('lab_pcs', ['asset_tag' => 'APU-1042', 'hostname' => 'apu-pc-042']);
    }

    public function test_an_asset_tag_is_stored_uppercase(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.lab-pcs.store'), $this->validPayload(['asset_tag' => 'apu-1042']))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('lab_pcs', ['asset_tag' => 'APU-1042']);
    }

    public function test_a_duplicate_asset_tag_is_rejected(): void
    {
        LabPc::factory()->create(['asset_tag' => 'APU-1042']);

        $this->actingAs($this->admin())
            ->post(route('admin.lab-pcs.store'), $this->validPayload())
            ->assertSessionHasErrors('asset_tag');

        $this->assertDatabaseCount('lab_pcs', 1);
    }

    public function test_a_retired_machine_still_holds_its_asset_tag(): void
    {
        // The tag is the link to years of inspection history. Letting a new
        // machine take it would silently reattach that history to another box.
        $pc = LabPc::factory()->create(['asset_tag' => 'APU-1042']);
        $pc->delete();

        $this->actingAs($this->admin())
            ->post(route('admin.lab-pcs.store'), $this->validPayload())
            ->assertSessionHasErrors('asset_tag');
    }

    public function test_warranty_cannot_expire_before_the_machine_was_bought(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.lab-pcs.store'), $this->validPayload([
                'purchase_date' => '2024-01-15',
                'warranty_expiry' => '2023-01-15',
            ]))
            ->assertSessionHasErrors('warranty_expiry');
    }

    public function test_retired_cannot_be_set_from_the_form(): void
    {
        /*
         * Retirement writes two columns together — the status and the
         * soft-delete stamp. Accepting `retired` here would set one without
         * the other, leaving a machine that is retired according to a report
         * and operational according to every list on screen.
         */
        $this->actingAs($this->admin())
            ->post(route('admin.lab-pcs.store'), $this->validPayload([
                'status' => DeviceStatus::Retired->value,
            ]))
            ->assertSessionHasErrors('status');

        $this->assertDatabaseCount('lab_pcs', 0);
    }

    // ------------------------------------------------------------ edit

    public function test_an_admin_can_edit_a_machine(): void
    {
        $pc = LabPc::factory()->create(['asset_tag' => 'APU-1042']);

        $this->actingAs($this->admin())
            ->patch(route('admin.lab-pcs.update', $pc), $this->validPayload([
                'location_id' => $pc->location_id,
                'status' => DeviceStatus::Degraded->value,
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame(DeviceStatus::Degraded, $pc->fresh()->status);
    }

    public function test_the_form_cannot_write_the_risk_score(): void
    {
        // risk_score and risk_band belong to M7's scoring run. A form that
        // could set them would let anyone talk a machine down to low risk.
        $pc = LabPc::factory()->create();

        $this->actingAs($this->admin())
            ->patch(route('admin.lab-pcs.update', $pc), $this->validPayload([
                'asset_tag' => $pc->asset_tag,
                'location_id' => $pc->location_id,
                'risk_score' => 0.1,
                'risk_band' => 'low',
            ]));

        $this->assertNull($pc->fresh()->risk_band);
    }

    // ------------------------------------------------------------ retire

    public function test_retiring_a_machine_keeps_the_record_and_marks_it_retired(): void
    {
        $pc = LabPc::factory()->create();

        $this->actingAs($this->admin())
            ->delete(route('admin.lab-pcs.destroy', $pc))
            ->assertRedirect(route('admin.lab-pcs.index'));

        // Both halves, together: the row survives, and its status agrees with
        // the soft-delete stamp rather than contradicting it.
        $retired = LabPc::withTrashed()->find($pc->id);

        $this->assertNotNull($retired);
        $this->assertTrue($retired->trashed());
        $this->assertSame(DeviceStatus::Retired, $retired->status);
    }

    public function test_a_retired_machine_drops_out_of_the_default_list(): void
    {
        $kept = LabPc::factory()->create(['asset_tag' => 'APU-0001']);
        $gone = LabPc::factory()->create(['asset_tag' => 'APU-0002']);
        $gone->delete();

        $this->actingAs($this->admin())
            ->get(route('admin.lab-pcs.index'))
            ->assertOk()
            ->assertSee($kept->asset_tag)
            ->assertDontSee($gone->asset_tag);
    }

    public function test_a_retired_machine_is_listed_when_asked_for(): void
    {
        $gone = LabPc::factory()->create(['asset_tag' => 'APU-0002']);
        $gone->delete();

        $this->actingAs($this->admin())
            ->get(route('admin.lab-pcs.index', ['retired' => 1]))
            ->assertOk()
            ->assertSee($gone->asset_tag);
    }

    public function test_a_retired_machine_offers_no_edit_link(): void
    {
        // Edit is not withTrashed, so the link was a 404.
        $kept = LabPc::factory()->create();
        $gone = LabPc::factory()->create();
        $gone->delete();

        $this->actingAs($this->admin())
            ->get(route('admin.lab-pcs.index', ['retired' => 1]))
            ->assertOk()
            ->assertSee(route('admin.lab-pcs.edit', $kept))
            ->assertDontSee(route('admin.lab-pcs.edit', $gone));
    }

    public function test_a_retired_machine_keeps_its_history_reachable(): void
    {
        /*
         * The whole point of retiring instead of deleting. Without
         * withTrashed on the resource route this 404s, and the history that
         * retirement was supposed to preserve is unreachable.
         */
        $pc = LabPc::factory()->create();
        $pc->delete();

        $this->actingAs($this->admin())
            ->get(route('admin.lab-pcs.show', $pc))
            ->assertOk()
            ->assertSee($pc->asset_tag);
    }

    public function test_a_retired_machine_can_be_returned_to_service(): void
    {
        $pc = LabPc::factory()->create();
        $pc->delete();

        $this->actingAs($this->admin())
            ->patch(route('admin.lab-pcs.restore', $pc))
            ->assertRedirect(route('admin.lab-pcs.show', $pc));

        $restored = LabPc::withTrashed()->find($pc->id);

        $this->assertFalse($restored->trashed());
        $this->assertSame(DeviceStatus::Operational, $restored->status);
    }
}
