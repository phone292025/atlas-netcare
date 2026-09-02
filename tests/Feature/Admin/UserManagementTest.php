<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * M1 — account management.
 *
 * This is the only way a person enters the system, so the things worth pinning
 * are the ones that would either lock everyone out or let someone in who
 * should not be.
 *
 * @see docs/modules/M1-identity-and-audit.md
 */
class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return $this->userWith(UserRole::Admin);
    }

    private function userWith(UserRole $role, bool $active = true): User
    {
        $user = User::factory()->create();
        $user->forceFill(['role' => $role, 'is_active' => $active])->save();

        return $user->fresh();
    }

    // ------------------------------------------------------------ access

    public function test_an_engineer_cannot_reach_user_management(): void
    {
        $this->actingAs($this->userWith(UserRole::Engineer))
            ->get(route('admin.users.index'))
            ->assertForbidden();
    }

    public function test_an_assistant_cannot_reach_user_management(): void
    {
        $this->actingAs($this->userWith(UserRole::Assistant))
            ->get(route('admin.users.index'))
            ->assertForbidden();
    }

    public function test_a_deactivated_admin_cannot_reach_user_management(): void
    {
        // FR-106 through the role middleware.
        $this->actingAs($this->userWith(UserRole::Admin, active: false))
            ->get(route('admin.users.index'))
            ->assertForbidden();
    }

    public function test_an_admin_sees_the_user_list(): void
    {
        $admin = $this->admin();
        $other = $this->userWith(UserRole::Engineer);

        $this->actingAs($admin)
            ->get(route('admin.users.index'))
            ->assertOk()
            ->assertSee($other->name)
            ->assertSee($other->email);
    }

    // ------------------------------------------------------------ creating

    public function test_an_admin_creates_an_account(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.users.store'), [
                'name' => 'Nyan Lin Tun',
                'email' => 'nyan@apu.test',
                'tp_number' => 'TP086590',
                'role' => UserRole::Assistant->value,
                'password' => 'correct-horse-battery',
                'password_confirmation' => 'correct-horse-battery',
            ])
            ->assertSessionHasNoErrors();

        $created = User::where('email', 'nyan@apu.test')->firstOrFail();

        $this->assertSame(UserRole::Assistant, $created->role);
        $this->assertTrue($created->is_active);
        $this->assertTrue($created->must_change_password, 'A new account must change the password an admin chose.');
    }

    public function test_a_new_account_can_sign_in_with_the_password_the_admin_set(): void
    {
        $this->actingAs($this->admin())->post(route('admin.users.store'), [
            'name' => 'Swan Yee Htet',
            'email' => 'swan@apu.test',
            'role' => UserRole::Engineer->value,
            'password' => 'correct-horse-battery',
            'password_confirmation' => 'correct-horse-battery',
        ]);

        auth()->logout();

        $this->post('/login', ['email' => 'swan@apu.test', 'password' => 'correct-horse-battery'])
            ->assertRedirect();

        $this->assertAuthenticated();
    }

    public function test_a_duplicate_email_is_rejected(): void
    {
        $existing = $this->userWith(UserRole::Engineer);

        $this->actingAs($this->admin())
            ->post(route('admin.users.store'), [
                'name' => 'Someone Else',
                'email' => $existing->email,
                'role' => UserRole::Assistant->value,
                'password' => 'correct-horse-battery',
                'password_confirmation' => 'correct-horse-battery',
            ])
            ->assertSessionHasErrors('email');
    }

    public function test_an_engineer_cannot_create_an_account(): void
    {
        $this->actingAs($this->userWith(UserRole::Engineer))
            ->post(route('admin.users.store'), [
                'name' => 'Sneaky', 'email' => 'sneaky@apu.test',
                'role' => UserRole::Admin->value,
                'password' => 'correct-horse-battery',
                'password_confirmation' => 'correct-horse-battery',
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('users', ['email' => 'sneaky@apu.test']);
    }

    // ------------------------------------------------------------ editing

    public function test_an_admin_changes_another_users_role(): void
    {
        $target = $this->userWith(UserRole::Assistant);

        $this->actingAs($this->admin())
            ->patch(route('admin.users.update', $target), [
                'name' => $target->name,
                'email' => $target->email,
                'role' => UserRole::Engineer->value,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(UserRole::Engineer, $target->fresh()->role);
    }

    public function test_an_admin_cannot_change_their_own_role(): void
    {
        // The only way to leave the system with no administrator at all.
        $admin = $this->admin();

        $this->actingAs($admin)->patch(route('admin.users.update', $admin), [
            'name' => $admin->name,
            'email' => $admin->email,
            'role' => UserRole::Assistant->value,
        ]);

        $this->assertSame(UserRole::Admin, $admin->fresh()->role, 'An admin demoted themselves.');
    }

    public function test_leaving_the_password_blank_keeps_the_existing_one(): void
    {
        $target = $this->userWith(UserRole::Assistant);
        $before = $target->password;

        $this->actingAs($this->admin())->patch(route('admin.users.update', $target), [
            'name' => 'Renamed Person',
            'email' => $target->email,
            'role' => UserRole::Assistant->value,
            'password' => '',
        ])->assertSessionHasNoErrors();

        $this->assertSame($before, $target->fresh()->password);
        $this->assertSame('Renamed Person', $target->fresh()->name);
    }

    // ------------------------------------------------- deactivation

    public function test_an_admin_deactivates_an_account_without_deleting_it(): void
    {
        $target = $this->userWith(UserRole::Assistant);

        $this->actingAs($this->admin())
            ->patch(route('admin.users.toggle-active', $target));

        $this->assertFalse($target->fresh()->is_active);
        $this->assertNotNull(User::find($target->id), 'Deactivation must not delete the record.');
    }

    public function test_an_admin_cannot_deactivate_themselves(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->patch(route('admin.users.toggle-active', $admin))
            ->assertForbidden();

        $this->assertTrue($admin->fresh()->is_active);
    }

    public function test_a_deactivated_user_cannot_sign_in(): void
    {
        // FR-106 at the login form, which is the half the middleware cannot
        // cover. See the note against FR-106 in docs/01-requirements.md.
        $user = $this->userWith(UserRole::Engineer, active: false);

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    // ------------------------------------------------------------ auditing

    public function test_creating_an_account_is_recorded_against_the_admin_who_did_it(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.users.store'), [
            'name' => 'Audited Person', 'email' => 'audited@apu.test',
            'role' => UserRole::Assistant->value,
            'password' => 'correct-horse-battery',
            'password_confirmation' => 'correct-horse-battery',
        ]);

        $created = User::where('email', 'audited@apu.test')->firstOrFail();

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'action' => 'created',
            'auditable_id' => $created->id,
            'description' => 'audited@apu.test',
        ]);
    }

    public function test_an_update_records_which_fields_changed(): void
    {
        $admin = $this->admin();
        $target = $this->userWith(UserRole::Assistant);

        $this->actingAs($admin)->patch(route('admin.users.update', $target), [
            'name' => 'A New Name',
            'email' => $target->email,
            'role' => UserRole::Assistant->value,
        ]);

        $log = AuditLog::where('action', 'updated')->where('auditable_id', $target->id)->latest('id')->firstOrFail();

        $this->assertArrayHasKey('name', $log->changes);
        $this->assertSame($admin->id, $log->user_id);
    }

    public function test_a_save_that_changes_nothing_writes_no_audit_row(): void
    {
        $admin = $this->admin();
        $target = $this->userWith(UserRole::Assistant);
        AuditLog::query()->delete();

        $this->actingAs($admin)->patch(route('admin.users.update', $target), [
            'name' => $target->name,
            'email' => $target->email,
            'role' => UserRole::Assistant->value,
        ]);

        $this->assertSame(0, AuditLog::where('action', 'updated')->count());
    }

    public function test_the_audit_log_screen_is_admin_only(): void
    {
        $this->actingAs($this->userWith(UserRole::Engineer))
            ->get(route('admin.audit.index'))
            ->assertForbidden();

        $this->actingAs($this->admin())
            ->get(route('admin.audit.index'))
            ->assertOk();
    }
}
