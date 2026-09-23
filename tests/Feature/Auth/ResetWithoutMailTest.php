<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

/**
 * Resetting a password on a machine that cannot send mail.
 *
 * FR-102 is an approved requirement, and delivery needs a Google app password.
 * That is a key to a personal Google account, so it can never be committed,
 * so **nobody cloning this project can send email**. Before this, a reviewer
 * clicking "forgot password" on a fresh clone got a dead end, and a working
 * requirement looked broken when it was only undelivered.
 *
 * The link now appears on the page. The token behind it is real, single-use
 * and still expires; only where it is shown changes.
 *
 * @see docs/02-open-questions.md Q11
 */
class ResetWithoutMailTest extends TestCase
{
    use RefreshDatabase;

    /** A machine with no mail account: local, debug on, nothing configured. */
    private function freshClone(): void
    {
        config([
            'app.debug' => true,
            'mail.default' => 'log',
            'atlas.mail_configured' => false,
        ]);

        $this->asLocal();
    }

    public function test_the_link_appears_on_the_page_when_mail_cannot_be_sent(): void
    {
        $this->freshClone();
        $user = User::factory()->create();

        $this->post(route('password.email'), ['email' => $user->email])
            ->assertSessionHas('reset_link');

        $this->get(route('password.request'))
            ->assertOk()
            ->assertSee('Here is the reset link')
            ->assertSee('reset-password/', false);
    }

    public function test_the_shown_link_actually_works(): void
    {
        /*
         * The point of the whole thing. A link that is displayed but does not
         * open the reset form would be worse than no link, because it looks
         * like the feature works.
         */
        $this->freshClone();
        $user = User::factory()->create();

        $this->post(route('password.email'), ['email' => $user->email]);
        $link = session('reset_link');

        $this->assertNotNull($link);

        $this->get($link)
            ->assertOk()
            ->assertSee('New password');
    }

    public function test_the_password_can_be_changed_through_it(): void
    {
        // End to end, with no mail account anywhere: request, follow, reset,
        // sign in with the new password.
        $this->freshClone();
        $user = User::factory()->create();

        $this->post(route('password.email'), ['email' => $user->email]);

        $link = session('reset_link');
        parse_str(parse_url($link, PHP_URL_QUERY) ?? '', $query);
        $token = basename(parse_url($link, PHP_URL_PATH));

        $this->post(route('password.store'), [
            'token' => $token,
            'email' => $query['email'],
            'password' => 'BrandNewPassword!2026',
            'password_confirmation' => 'BrandNewPassword!2026',
        ])->assertSessionHasNoErrors()->assertRedirect(route('login'));

        $this->post(route('login'), [
            'email' => $user->email,
            'password' => 'BrandNewPassword!2026',
        ])->assertRedirect();

        $this->assertAuthenticated();
    }

    public function test_no_link_is_shown_once_mail_is_configured(): void
    {
        /*
         * A machine that can genuinely send mail has no business printing a
         * reset token on a page anybody can open. This is the condition that
         * keeps it a development affordance rather than a hole.
         */
        config([
            'app.debug' => true,
            'mail.default' => 'smtp',
            'atlas.mail_configured' => true,
        ]);
        $this->asLocal();

        $user = User::factory()->create();

        $this->post(route('password.email'), ['email' => $user->email])
            ->assertSessionMissing('reset_link');
    }

    public function test_no_link_is_shown_outside_local(): void
    {
        // Belt and braces. Even misconfigured, a deployment never does this.
        config([
            'app.debug' => true,
            'mail.default' => 'log',
            'atlas.mail_configured' => false,
        ]);
        $this->app['env'] = 'production';
        $this->withoutMiddleware(ValidateCsrfToken::class);

        $user = User::factory()->create();

        $this->post(route('password.email'), ['email' => $user->email])
            ->assertSessionMissing('reset_link');
    }

    public function test_no_link_is_shown_with_debug_off(): void
    {
        config([
            'app.debug' => false,
            'mail.default' => 'log',
            'atlas.mail_configured' => false,
        ]);
        $this->asLocal();

        $user = User::factory()->create();

        $this->post(route('password.email'), ['email' => $user->email])
            ->assertSessionMissing('reset_link');
    }

    public function test_the_command_finds_a_link_in_the_log(): void
    {
        // The fallback, for anybody who would rather use the terminal, and the
        // way to recover a link after the flash message has gone.
        $this->asLocal();
        config(['mail.default' => 'log']);

        $user = User::factory()->create();
        $token = Password::createToken($user);
        $url = url(route('password.reset', ['token' => $token, 'email' => $user->email], false));

        // A log of its own. Appending to storage/logs/laravel.log left a fake
        // link there on every run, and the command then printed that instead
        // of the developer's real one.
        $log = tempnam(sys_get_temp_dir(), 'atlas-log');
        file_put_contents($log, "[test] a logged message containing {$url} inline\n");
        config(['logging.channels.single.path' => $log]);

        try {
            $this->artisan('atlas:reset-link')
                ->expectsOutputToContain($token)
                ->assertExitCode(0);
        } finally {
            unlink($log);
        }
    }

    public function test_the_command_refuses_outside_local(): void
    {
        // A reset token is a way into an account.
        $this->app['env'] = 'production';
        $this->withoutMiddleware(ValidateCsrfToken::class);

        $this->artisan('atlas:reset-link')
            ->expectsOutputToContain('only runs locally')
            ->assertExitCode(1);
    }

    /**
     * Pretend this is a developer's machine.
     *
     * Changing the environment turns CSRF protection back on, because
     * `runningUnitTests()` checks for "testing" and the framework skips the
     * middleware on that basis alone. Every request here would then 419, which
     * looks exactly like the feature being broken. It is not: it is the price
     * of testing something gated on the environment.
     */
    private function asLocal(): void
    {
        $this->app['env'] = 'local';
        $this->withoutMiddleware(ValidateCsrfToken::class);
    }
}
