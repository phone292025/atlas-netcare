<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;

/**
 * FR-102 delivery, which is the half that was actually broken.
 *
 * The flow itself is covered by PasswordResetTest. These are about what
 * happens around it: whether the screen tells the truth when no mail can be
 * sent, whether a refused SMTP login is survivable, and whether the email
 * that arrives looks like it came from this system.
 *
 * None of this proves SMTP works — the suite fakes the mailer, so it never
 * can. `php artisan atlas:mail-test` is the only thing that proves that.
 *
 * @see docs/02-open-questions.md Q11
 */
class PasswordResetDeliveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_screen_says_the_link_only_went_to_a_log_file(): void
    {
        config(['app.debug' => true, 'atlas.mail_configured' => false, 'mail.default' => 'log']);

        $this->get(route('password.request'))
            ->assertOk()
            ->assertSee('No mail server is configured')
            ->assertSee('storage/logs/laravel.log');
    }

    public function test_the_screen_names_the_missing_app_password(): void
    {
        /*
         * The second way to be broken, and the one the project is in until
         * somebody pastes a Google app password. Naming MAIL_PASSWORD matters:
         * "no mail server is configured" alone sends people to check XAMPP.
         */
        config(['app.debug' => true, 'atlas.mail_configured' => false, 'mail.default' => 'smtp']);

        $this->get(route('password.request'))
            ->assertOk()
            ->assertSee('MAIL_PASSWORD')
            ->assertSee('atlas:mail-test')
            ->assertDontSee('storage/logs/laravel.log');
    }

    public function test_no_warning_once_mail_is_configured(): void
    {
        config(['app.debug' => true, 'atlas.mail_configured' => true, 'mail.default' => 'smtp']);

        $this->get(route('password.request'))
            ->assertOk()
            ->assertDontSee('No mail server is configured');
    }

    public function test_the_warning_can_never_reach_a_deployment(): void
    {
        // Belt and braces. Even misconfigured, a production build says nothing
        // about .env keys to whoever is looking at the login screen.
        config(['app.debug' => false, 'atlas.mail_configured' => false, 'mail.default' => 'log']);

        $this->get(route('password.request'))
            ->assertOk()
            ->assertDontSee('No mail server is configured')
            ->assertDontSee('MAIL_PASSWORD');
    }

    public function test_a_refused_smtp_login_is_an_error_message_not_a_stack_trace(): void
    {
        /*
         * Gmail answers a bad app password with a 535 during send, which is
         * inside Password::sendResetLink(), which is inside the controller.
         * Unhandled, the user gets a 500 on the password screen — the worst
         * possible place to show a stack trace, because it is the one screen
         * reachable without signing in.
         */
        User::factory()->create(['email' => 'swan@apu.test']);

        Password::shouldReceive('sendResetLink')
            ->once()
            ->andThrow(new TransportException('Expected response code "250" but got code "535".'));

        $this->from(route('password.request'))
            ->post(route('password.email'), ['email' => 'swan@apu.test'])
            ->assertRedirect(route('password.request'))
            ->assertSessionHasErrors('email');
    }

    public function test_the_email_is_from_this_system_and_not_from_breeze(): void
    {
        Notification::fake();

        $user = User::factory()->create(['name' => 'Swan Yee Htet']);

        $this->post(route('password.email'), ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user) {
            $mail = $notification->toMail($user);

            $this->assertSame('Reset your Atlas NetCare password', $mail->subject);
            $this->assertSame('Hello Swan Yee Htet,', $mail->greeting);
            $this->assertStringContainsString('Asia Pacific University', $mail->salutation);
            $this->assertStringContainsString('reset-password/', $mail->actionUrl);

            return true;
        });
    }

    public function test_the_email_states_the_expiry_from_config_rather_than_a_number_in_a_sentence(): void
    {
        /*
         * Somebody will change auth.passwords.users.expire eventually. An
         * email that confidently states the old number is worse than one that
         * says nothing, because the user believes it.
         */
        config(['auth.passwords.users.expire' => 17]);

        Notification::fake();

        $user = User::factory()->create();
        $this->post(route('password.email'), ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user) {
            $mail = $notification->toMail($user);

            // A ->line() added after ->action() lands in outroLines.
            $lines = implode(' ', array_map('strval', [...$mail->introLines, ...$mail->outroLines]));

            $this->assertStringContainsString('17 minutes', $lines);

            return true;
        });
    }

    public function test_the_throttle_says_how_long_to_wait(): void
    {
        /*
         * One request per address per minute. Laravel answers a second one
         * with "Please wait before retrying", which does not say how long and
         * reads like a broken button — and the person most likely to click
         * twice is a marker seeing the screen for the first time.
         */
        config(['auth.passwords.users.throttle' => 90]);

        $user = User::factory()->create();

        $this->post(route('password.email'), ['email' => $user->email]);

        $this->from(route('password.request'))
            ->post(route('password.email'), ['email' => $user->email])
            ->assertSessionHasErrors(['email' => 'A link was sent to that address moments ago. Wait 90 seconds before asking for another.']);
    }

    public function test_the_throttle_is_still_a_real_control(): void
    {
        // The point of the friendlier wording is the wording. A second
        // request inside the window must still be refused.
        Notification::fake();

        $user = User::factory()->create();

        $this->post(route('password.email'), ['email' => $user->email]);
        $this->post(route('password.email'), ['email' => $user->email]);

        Notification::assertSentToTimes($user, ResetPassword::class, 1);
    }

    public function test_a_reset_link_works_in_a_browser_that_is_already_signed_in(): void
    {
        /*
         * Breeze puts this route behind `guest`, so an authenticated browser
         * is redirected into the application and the link looks broken. That
         * is what happened on a phone: tapping the link from Gmail opened the
         * profile screen with no explanation.
         *
         * Nobody controls which browser opens a link from their inbox.
         */
        $user = User::factory()->create();
        $token = Password::createToken($user);

        $this->actingAs($user)
            ->get(route('password.reset', ['token' => $token, 'email' => $user->email]))
            ->assertOk()
            ->assertSee('New password');
    }

    public function test_the_form_names_who_is_signed_in(): void
    {
        $reader = User::factory()->create(['name' => 'Nyan Lin Tun']);
        $owner = User::factory()->create();

        $this->actingAs($reader)
            ->get(route('password.reset', [
                'token' => Password::createToken($owner),
                'email' => $owner->email,
            ]))
            ->assertOk()
            ->assertSee('You are signed in as Nyan Lin Tun');
    }

    public function test_the_form_says_nothing_about_sessions_when_there_is_none(): void
    {
        $user = User::factory()->create();

        $this->get(route('password.reset', [
            'token' => Password::createToken($user),
            'email' => $user->email,
        ]))
            ->assertOk()
            ->assertDontSee('You are signed in as');
    }

    public function test_asking_for_a_link_is_still_a_signed_out_thing_to_do(): void
    {
        // The request form stays behind `guest`. Somebody already signed in
        // changes their password from the profile screen, which asks for the
        // current one.
        $this->actingAs(User::factory()->create())
            ->get(route('password.request'))
            ->assertRedirect();
    }

    public function test_resetting_signs_the_account_out_everywhere(): void
    {
        /*
         * Breeze rotates remember_token, which kills "remember me" cookies and
         * nothing else. An active session survives a reset untouched — so
         * somebody who has taken over an account stays signed in while the
         * owner resets the password and believes they have locked them out.
         *
         * Locking them out is the entire reason the feature exists.
         */
        config(['session.driver' => 'database']);

        $user = User::factory()->create();
        $other = User::factory()->create();

        foreach ([$user, $user, $other] as $i => $owner) {
            DB::table('sessions')->insert([
                'id' => 'session-'.$i,
                'user_id' => $owner->id,
                'ip_address' => '127.0.0.1',
                'user_agent' => 'phpunit',
                'payload' => '',
                'last_activity' => now()->timestamp,
            ]);
        }

        $this->post(route('password.store'), [
            'token' => Password::createToken($user),
            'email' => $user->email,
            'password' => 'ANewPassword!2026',
            'password_confirmation' => 'ANewPassword!2026',
        ])->assertSessionHasNoErrors();

        $this->assertSame(0, DB::table('sessions')->where('user_id', $user->id)->count());

        // And only that account. Resetting one password must not sign out the
        // rest of the campus.
        $this->assertSame(1, DB::table('sessions')->where('user_id', $other->id)->count());
    }

    public function test_a_reset_still_succeeds_without_a_database_session_driver(): void
    {
        // The file and array drivers have nothing to query. A reset must not
        // depend on how sessions happen to be stored.
        config(['session.driver' => 'file']);

        $user = User::factory()->create();

        $this->post(route('password.store'), [
            'token' => Password::createToken($user),
            'email' => $user->email,
            'password' => 'ANewPassword!2026',
            'password_confirmation' => 'ANewPassword!2026',
        ])->assertSessionHasNoErrors()->assertRedirect(route('login'));
    }
}
