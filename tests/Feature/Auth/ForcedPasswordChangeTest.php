<?php

namespace Tests\Feature\Auth;

use App\Enums\UserRole;
use App\Models\User;
use Database\Seeders\DemoDataSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

/**
 * Nobody uses Atlas NetCare on a password somebody else chose for them.
 *
 * `must_change_password` existed as a column, was set in three places and was
 * shown as a badge reading "Must be changed at next sign-in" — and absolutely
 * nothing read it. You signed in and went to your dashboard. The badge was a
 * claim about behaviour that did not exist, and `11-installation-guide.md` made
 * the same claim to anybody following it to install the system.
 *
 * Q6 asked the team whether to build this and was never answered, so under the
 * project's own rule the column was written and the middleware was not. Q6 was
 * decided on 2026-09-04.
 *
 * @see app/Http/Middleware/EnsurePasswordIsChanged.php
 * @see docs/02-open-questions.md Q6
 */
class ForcedPasswordChangeTest extends TestCase
{
    use RefreshDatabase;

    private function flaggedUser(string $role = 'assistant'): User
    {
        $user = User::factory()->create(['password' => Hash::make('admin-chose-this')]);

        $user->forceFill([
            'role' => UserRole::from($role),
            'is_active' => true,
            'must_change_password' => true,
        ])->save();

        return $user;
    }

    public function test_a_flagged_user_cannot_reach_an_ordinary_screen(): void
    {
        $this->actingAs($this->flaggedUser())
            ->get(route('notifications.index'))
            ->assertRedirect(route('profile.edit'))
            ->assertSessionHas('warning');
    }

    public function test_the_block_applies_to_every_role(): void
    {
        /*
         * The reason this is middleware on the `web` group rather than a check
         * bolted to one controller: an administrator's password is the one most
         * worth forcing, and an engineer sits between the two.
         */
        $screens = [
            'assistant' => 'assistant.shifts.today',
            'engineer' => 'engineer.dashboard',
            'admin' => 'admin.users.index',
        ];

        foreach ($screens as $role => $route) {
            $this->actingAs($this->flaggedUser($role))
                ->get(route($route))
                ->assertRedirect(route('profile.edit'));
        }
    }

    public function test_the_profile_screen_itself_stays_open(): void
    {
        // Or the redirect is a loop, and the account is bricked rather than
        // restricted.
        $this->actingAs($this->flaggedUser())
            ->get(route('profile.edit'))
            ->assertOk();
    }

    public function test_the_screen_they_land_on_says_why_and_offers_the_form(): void
    {
        /*
         * Being silently bounced to Profile is indistinguishable from a broken
         * link. The banner has to actually render — the flash is set with the
         * `warning` key precisely because the layout renders that one, and a
         * typo there would leave the redirect working and the explanation
         * invisible.
         */
        $user = $this->flaggedUser();

        $this->actingAs($user)->get(route('notifications.index'));

        $this->actingAs($user)
            ->get(route('profile.edit'))
            ->assertOk()
            ->assertSee('An administrator set this password')
            ->assertSee('Update Password');
    }

    public function test_they_can_still_sign_out(): void
    {
        /*
         * Somebody who cannot remember the password an administrator read out
         * to them needs a way off the screen that is not closing the tab. This
         * is the difference between a control and a trap.
         */
        $this->actingAs($this->flaggedUser())
            ->post(route('logout'))
            ->assertRedirect('/');

        $this->assertGuest();
    }

    public function test_changing_the_password_clears_the_flag_and_opens_the_system(): void
    {
        // The whole point, end to end.
        $user = $this->flaggedUser();

        $this->actingAs($user)->put(route('password.update'), [
            'current_password' => 'admin-chose-this',
            'password' => 'MyOwnPassword!2026',
            'password_confirmation' => 'MyOwnPassword!2026',
        ])->assertSessionHasNoErrors();

        $this->assertFalse($user->fresh()->must_change_password);

        $this->actingAs($user->fresh())
            ->get(route('notifications.index'))
            ->assertOk();
    }

    public function test_a_failed_change_leaves_the_flag_alone(): void
    {
        // Getting the current password wrong must not let somebody past.
        $user = $this->flaggedUser();

        $this->actingAs($user)->put(route('password.update'), [
            'current_password' => 'not-the-right-one',
            'password' => 'MyOwnPassword!2026',
            'password_confirmation' => 'MyOwnPassword!2026',
        ])->assertSessionHasErrors('current_password', errorBag: 'updatePassword');

        $this->assertTrue($user->fresh()->must_change_password);
    }

    public function test_an_unflagged_user_is_untouched(): void
    {
        $user = User::factory()->create();
        $user->forceFill(['role' => UserRole::Assistant, 'is_active' => true])->save();

        $this->actingAs($user)
            ->get(route('notifications.index'))
            ->assertOk();
    }

    public function test_a_json_caller_is_refused_rather_than_redirected(): void
    {
        /*
         * The dashboard polls for telemetry. A 302 to an HTML page gives it
         * something it cannot parse, and letting it through would mean the data
         * is readable while the screens are not.
         */
        $this->actingAs($this->flaggedUser('engineer'))
            ->getJson(route('engineer.dashboard'))
            ->assertForbidden();
    }

    public function test_resetting_through_the_emailed_link_also_clears_it(): void
    {
        /*
         * The path that would otherwise loop: somebody forgets the password an
         * administrator gave them, resets it through the link, signs in with a
         * password they chose — and gets sent to the profile screen to choose
         * another one.
         */
        $user = $this->flaggedUser();
        $token = Password::createToken($user);

        $this->post(route('password.store'), [
            'token' => $token,
            'email' => $user->email,
            'password' => 'ChosenByMe!2026',
            'password_confirmation' => 'ChosenByMe!2026',
        ])->assertSessionHasNoErrors();

        $this->assertFalse($user->fresh()->must_change_password);
    }

    /*
    |---------------------------------------------------------------------
    | The three places that set the flag
    |---------------------------------------------------------------------
    |
    | Enforcement is only worth anything if the flag is still being set where
    | it is meant to be. These are the writes the middleware acts on.
    */

    public function test_the_seeded_administrator_is_flagged(): void
    {
        // 11-installation-guide.md tells a reader the administrator "asks for a
        // new one immediately". This is the assertion that makes that true.
        $this->seed(ReferenceSeeder::class);

        $admin = User::where('email', config('atlas.first_admin.email'))->firstOrFail();

        $this->assertTrue($admin->must_change_password);
    }

    public function test_an_administrator_creating_an_account_flags_it(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['role' => UserRole::Admin, 'is_active' => true])->save();

        $this->actingAs($admin)->post(route('admin.users.store'), [
            'name' => 'New Assistant',
            'tp_number' => 'TP099999',
            'email' => 'new.assistant@atlas.local',
            'role' => 'assistant',
            'password' => 'AdminChose!2026',
            'password_confirmation' => 'AdminChose!2026',
        ])->assertSessionHasNoErrors();

        $this->assertTrue(User::where('email', 'new.assistant@atlas.local')->firstOrFail()->must_change_password);
    }

    public function test_demo_accounts_are_not_flagged_so_the_demo_still_works(): void
    {
        /*
         * The regression that would hurt most. Flagging the shared demo
         * accounts would mean every reviewer signing in as the engineer is
         * bounced to a password form, the first person to get there changes a
         * password five other people are using, and the documented logins stop
         * working.
         */
        $this->seed(DemoDataSeeder::class);

        $this->assertSame(0, User::where('is_demo', true)->where('must_change_password', true)->count());
    }
}
