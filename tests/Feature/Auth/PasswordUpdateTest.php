<?php

namespace Tests\Feature\Auth;

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Changing your own password from the profile, and an administrator's reset:
 * what each requires, and that it signs out every other session.
 */
class PasswordUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_password_can_be_updated(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->from('/profile')
            ->put('/password', [
                'current_password' => 'password',
                'password' => 'new-password',
                'password_confirmation' => 'new-password',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile');

        $this->assertTrue(Hash::check('new-password', $user->refresh()->password));
    }

    public function test_changing_it_says_so_in_words(): void
    {
        // Same message bar, same leak: it read "password-updated".
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from('/profile')
            ->followingRedirects()
            ->put('/password', [
                'current_password' => 'password',
                'password' => 'new-password',
                'password_confirmation' => 'new-password',
            ])
            ->assertOk()
            ->assertDontSee('password-updated')
            ->assertSee('Password changed.');
    }

    public function test_correct_password_must_be_provided_to_update_password(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->from('/profile')
            ->put('/password', [
                'current_password' => 'wrong-password',
                'password' => 'new-password',
                'password_confirmation' => 'new-password',
            ]);

        $response
            ->assertSessionHasErrorsIn('updatePassword', 'current_password')
            ->assertRedirect('/profile');
    }

    /** A session row as the database driver writes it. */
    private function sessionFor(User $user, string $id): void
    {
        DB::table('sessions')->insert([
            'id' => $id, 'user_id' => $user->id, 'ip_address' => '10.0.0.9',
            'user_agent' => 'elsewhere', 'payload' => '', 'last_activity' => now()->getTimestamp(),
        ]);
    }

    public function test_changing_it_signs_out_every_other_session(): void
    {
        // Somebody who took the account kept their session after the owner
        // changed the password.
        config(['session.driver' => 'database']);
        $user = User::factory()->create();
        $this->sessionFor($user, 'someone-elses-session');

        $this->actingAs($user)
            ->from('/profile')
            ->put('/password', [
                'current_password' => 'password',
                'password' => 'new-password',
                'password_confirmation' => 'new-password',
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('sessions', ['id' => 'someone-elses-session']);
    }

    public function test_an_administrators_reset_signs_the_account_out(): void
    {
        config(['session.driver' => 'database']);
        $admin = User::factory()->create();
        $admin->forceFill(['role' => UserRole::Admin, 'is_active' => true])->save();
        $user = User::factory()->create();
        $user->forceFill(['role' => UserRole::Assistant, 'is_active' => true])->save();
        $this->sessionFor($user, 'their-session');

        $this->actingAs($admin)
            ->put(route('admin.users.update', $user), [
                'name' => $user->name, 'email' => $user->email, 'role' => 'assistant',
                'password' => 'Temp-password-1', 'password_confirmation' => 'Temp-password-1',
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('sessions', ['id' => 'their-session']);
        // And it is on record that the password changed, though not what to.
        $this->assertSame(AuditLog::SECRET_CHANGED, AuditLog::forSubject($user)->where('action', 'updated')->latest('id')->first()->changes['password']);
    }

    public function test_a_forced_change_refuses_the_password_somebody_else_chose(): void
    {
        $user = User::factory()->create();
        $user->forceFill(['must_change_password' => true])->save();

        $this->actingAs($user)
            ->from('/profile')
            ->put('/password', [
                'current_password' => 'password',
                'password' => 'password',
                'password_confirmation' => 'password',
            ])
            ->assertSessionHasErrorsIn('updatePassword', 'password');

        $this->assertTrue($user->fresh()->must_change_password);
    }
}
