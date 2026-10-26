<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Models\AccessPoint;
use App\Models\LabPc;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Clicking a column header twice sorts the list the other way.
 *
 * The header link toggled `direction=desc` and drew the arrow, but the three
 * controllers compared `$request->string('direction')`, a Stringable object,
 * with `=== 'desc'`, which is never true. The arrow said descending and the
 * list stayed ascending.
 */
class ListSortingTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $user = User::factory()->create(['name' => 'Zz Admin']);
        $user->forceFill(['role' => UserRole::Admin, 'is_active' => true])->save();

        return $user->fresh();
    }

    public function test_the_users_list_sorts_descending(): void
    {
        $admin = $this->admin();
        User::factory()->create(['name' => 'Aaron First']);
        User::factory()->create(['name' => 'Mia Middle']);

        $this->actingAs($admin)
            ->get(route('admin.users.index', ['sort' => 'name', 'direction' => 'desc']))
            ->assertOk()
            ->assertSeeInOrder(['Zz Admin', 'Mia Middle', 'Aaron First']);

        $this->actingAs($admin)
            ->get(route('admin.users.index', ['sort' => 'name', 'direction' => 'asc']))
            ->assertSeeInOrder(['Aaron First', 'Mia Middle', 'Zz Admin']);
    }

    public function test_the_users_status_filter_means_what_it_says(): void
    {
        // The same Stringable comparison: `status=active` matched nobody active
        // and listed the deactivated accounts instead.
        $admin = $this->admin();
        User::factory()->create(['name' => 'Still Here']);
        User::factory()->create(['name' => 'Long Gone'])->forceFill(['is_active' => false])->save();

        $this->actingAs($admin)
            ->get(route('admin.users.index', ['status' => 'active']))
            ->assertSee('Still Here')
            ->assertDontSee('Long Gone');

        $this->actingAs($admin)
            ->get(route('admin.users.index', ['status' => 'inactive']))
            ->assertSee('Long Gone')
            ->assertDontSee('Still Here');
    }

    public function test_the_lab_pc_list_sorts_descending(): void
    {
        foreach (['APU-0001', 'APU-0002', 'APU-0003'] as $tag) {
            LabPc::factory()->create(['asset_tag' => $tag]);
        }

        $this->actingAs($this->admin())
            ->get(route('admin.lab-pcs.index', ['sort' => 'asset_tag', 'direction' => 'desc']))
            ->assertOk()
            ->assertSeeInOrder(['APU-0003', 'APU-0002', 'APU-0001']);
    }

    public function test_the_access_point_list_sorts_descending(): void
    {
        foreach (['AP-AA-01', 'AP-AA-02', 'AP-AA-03'] as $code) {
            AccessPoint::factory()->create(['code' => $code]);
        }

        $this->actingAs($this->admin())
            ->get(route('admin.aps.index', ['sort' => 'code', 'direction' => 'desc']))
            ->assertOk()
            ->assertSeeInOrder(['AP-AA-03', 'AP-AA-02', 'AP-AA-01']);
    }
}
