<?php

namespace Tests\Feature\Auth;

use App\Enums\UserRole;
use App\Http\Middleware\EnsureAccountIsActive;
use App\Models\Location;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * FR-106 for a session that already exists.
 *
 * Signing in is covered by UserManagementTest. These cover the other half: an
 * account deactivated while somebody is still signed in to it. Before
 * EnsureAccountIsActive, only role-prefixed routes refused it, and the shared
 * ones (raising tickets, notifications, the polling endpoints) stayed open for
 * as long as the session or its "remember me" cookie lasted.
 *
 * @see App\Http\Middleware\EnsureAccountIsActive
 */
class DeactivatedAccountTest extends TestCase
{
    use RefreshDatabase;

    private function user(UserRole $role = UserRole::Assistant): User
    {
        $user = User::factory()->create();
        $user->forceFill(['role' => $role])->save();

        return $user;
    }

    private function deactivate(User $user): void
    {
        $user->forceFill(['is_active' => false])->save();
    }

    public function test_an_active_account_is_let_through(): void
    {
        $this->actingAs($this->user())
            ->get(route('assistant.tickets.index'))
            ->assertOk();
    }

    public function test_a_deactivated_account_is_signed_out_on_its_next_request(): void
    {
        $user = $this->user();
        $this->actingAs($user);
        $this->deactivate($user);

        $this->get(route('assistant.tickets.index'))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors(['email' => EnsureAccountIsActive::MESSAGE]);

        $this->assertGuest();
    }

    public function test_a_deactivated_account_cannot_raise_a_ticket(): void
    {
        // The route is guarded by TicketPolicy rather than by a role, which is
        // exactly the kind the role middleware never saw.
        $user = $this->user();
        $this->actingAs($user);
        $this->deactivate($user);

        $this->post(route('assistant.tickets.store'), [
            'title' => 'Raised after deactivation',
            'description' => 'Should never be stored.',
            'category' => 'hardware',
            'priority' => 'critical',
            'location_id' => Location::factory()->create()->id,
        ])->assertRedirect(route('login'));

        $this->assertSame(0, Ticket::count());
    }

    public function test_the_polling_endpoints_answer_401_rather_than_a_login_page(): void
    {
        $user = $this->user(UserRole::Engineer);
        $this->actingAs($user);
        $this->deactivate($user);

        $this->getJson(route('api.dashboard'))->assertUnauthorized();
        $this->assertGuest();
    }

    public function test_role_guarded_areas_sign_the_account_out_as_well(): void
    {
        $admin = $this->user(UserRole::Admin);
        $this->actingAs($admin);
        $this->deactivate($admin);

        $this->get(route('admin.users.index'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_signing_out_retires_the_remember_me_cookie(): void
    {
        // Logging out cycles remember_token, so a "remember me" cookie issued
        // before the deactivation no longer matches anything.
        $user = $this->user();
        $before = $user->remember_token;

        $this->actingAs($user);
        $this->deactivate($user);
        $this->get(route('assistant.tickets.index'));

        $this->assertNotSame($before, $user->fresh()->remember_token);
    }

    public function test_is_active_read_back_from_the_database_is_a_real_boolean(): void
    {
        // Without the model cast Eloquent hands back int(0), and a strict
        // `=== false` guard fails open. This is how the role middleware once
        // let deactivated accounts through.
        $user = $this->user();
        $this->deactivate($user);

        $fromDatabase = User::findOrFail($user->getKey());

        $this->assertFalse($fromDatabase->is_active);

        $this->actingAs($fromDatabase)
            ->get(route('assistant.tickets.index'))
            ->assertRedirect(route('login'));
    }

    public function test_a_deactivated_accounts_calendar_feed_stops_working(): void
    {
        // The feed is authorised by its token alone, so deactivation has to be
        // checked there too.
        $user = $this->user();
        $user->forceFill(['calendar_token' => str_repeat('a', 48)])->save();

        $this->get(route('shifts.calendar.feed', str_repeat('a', 48)))->assertOk();

        $this->deactivate($user);

        $this->get(route('shifts.calendar.feed', str_repeat('a', 48)))->assertNotFound();
    }
}
