<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_page_is_displayed(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/profile')
            ->assertOk();
    }

    public function test_profile_information_can_be_updated(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch('/profile', [
                'name' => 'Test User',
                'email' => 'test@example.com',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile');

        $user->refresh();

        $this->assertSame('Test User', $user->name);
        $this->assertSame('test@example.com', $user->email);
        $this->assertNull($user->email_verified_at);
    }

    public function test_email_verification_status_is_unchanged_when_the_email_address_is_unchanged(): void
    {
        $user = User::factory()->create();

        $this
            ->actingAs($user)
            ->patch('/profile', [
                'name' => 'Test User',
                'email' => $user->email,
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile');

        $this->assertNotNull($user->refresh()->email_verified_at);
    }

    /**
     * Atlas NetCare is an internal, privilege-based system. Users cannot delete
     * their own accounts; administrators deactivate them instead, so that audit
     * history survives (NFR-06). See docs/modules/M1-identity-and-audit.md.
     */
    public function test_users_cannot_delete_their_own_account(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->delete('/profile', ['password' => 'password'])
            ->assertStatus(405); // Method Not Allowed: /profile exists, DELETE does not

        $this->assertDatabaseHas('users', ['id' => $user->id]);
    }

    /**
     * The profile form posting its way to an administrator account.
     *
     * `role`, `is_active` and `is_demo` are kept out of `User::$fillable` for
     * exactly this reason, and AGENTS.md states the rule. A rule is only worth
     * as much as the test that would notice it being broken: adding one word
     * to that array turns every profile form in the system into privilege
     * escalation, and nothing else in the suite would have failed.
     */
    public function test_the_profile_form_cannot_promote_anybody(): void
    {
        $user = User::factory()->create();
        $user->forceFill(['role' => UserRole::Assistant, 'is_active' => true])->save();

        $this->actingAs($user)
            ->patch('/profile', [
                'name' => 'Test User',
                'email' => 'still.an.assistant@atlas.local',
                'role' => UserRole::Admin->value,
                'is_active' => true,
                'is_demo' => false,
            ])
            ->assertRedirect('/profile');

        $this->assertSame(
            UserRole::Assistant,
            $user->fresh()->role,
            'An assistant promoted themselves through the profile form.',
        );
    }

    public function test_a_deactivated_account_is_turned_away_from_every_screen(): void
    {
        // Deactivation has to hold against a session that already exists, not
        // only at the login form. FR-106.
        $user = User::factory()->create();
        $user->forceFill(['role' => UserRole::Assistant, 'is_active' => true])->save();

        $this->actingAs($user)->get(route('assistant.shifts.today'))->assertOk();

        $user->forceFill(['is_active' => false])->save();

        $this->actingAs($user->fresh())
            ->get(route('assistant.shifts.today'))
            ->assertForbidden();
    }
}
