<?php

namespace Tests\Feature\Auth;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Breeze features this system deliberately does not have.
 *
 * Each one shipped in Sprint 0 with Breeze and sat there unaudited until a
 * password reset was tried on a phone and the screen claimed to have sent an
 * email it had not sent.
 *
 * These tests exist so nobody restores them by running a Breeze command and
 * assuming the defaults are wanted.
 *
 * @see routes/auth.php for the reasoning
 */
class RemovedAuthFeaturesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{string}>
     */
    public static function removedRoutes(): array
    {
        return [
            // User does not implement MustVerifyEmail and nothing carries the
            // `verified` middleware, so these could never do anything.
            'verification notice' => ['verification.notice'],
            'verification link' => ['verification.verify'],
            'verification resend' => ['verification.send'],

            // Its middleware was never applied to a route.
            'password confirmation' => ['password.confirm'],
        ];
    }

    #[DataProvider('removedRoutes')]
    public function test_the_route_stays_removed(string $name): void
    {
        $this->assertFalse(
            Route::has($name),
            "Route [{$name}] is registered again. It was removed on purpose — read routes/auth.php before restoring it.",
        );
    }

    public function test_password_reset_is_kept_because_it_is_an_approved_requirement(): void
    {
        /*
         * FR-102, and the proposal lists "recover password" for all three
         * roles. It was briefly deleted on 2026-08-29 for lying to the user,
         * and restored the same day once the proposal was checked. Being
         * broken is grounds for fixing a requirement, not removing it.
         *
         * What was broken is delivery, not the flow. See Q11.
         */
        $this->assertTrue(Route::has('password.request'));
        $this->assertTrue(Route::has('password.email'));

        $this->get(route('login'))->assertOk()->assertSee('Forgot your password');
    }

    public function test_the_reset_screen_admits_when_nothing_was_sent(): void
    {
        /*
         * With no mail server, Laravel's own success message still reads "We
         * have emailed your password reset link", which is untrue. Seven links
         * accumulated in the log before anybody noticed, because the screen
         * said otherwise.
         */
        config(['mail.default' => 'log', 'app.debug' => true]);

        $this->get(route('password.request'))
            ->assertOk()
            ->assertSee('No mail server is configured');
    }

    public function test_that_warning_never_shows_on_a_configured_deployment(): void
    {
        config(['mail.default' => 'smtp', 'app.debug' => false]);

        $this->get(route('password.request'))
            ->assertOk()
            ->assertDontSee('No mail server is configured');
    }

    public function test_an_administrator_can_still_reset_somebody_elses_password(): void
    {
        /*
         * The replacement, and the reason removing the emailed flow costs
         * nothing. It was already built in M1.
         */
        $admin = User::factory()->create();
        $admin->forceFill(['role' => UserRole::Admin, 'is_active' => true])->save();

        $assistant = User::factory()->create(['email' => 'swan@apu.test']);
        $assistant->forceFill(['role' => UserRole::Assistant, 'is_active' => true])->save();

        $this->actingAs($admin)->patch(route('admin.users.update', $assistant), [
            'name' => $assistant->name,
            'email' => $assistant->email,
            'role' => UserRole::Assistant->value,
            'password' => 'ANewPassword!2026',
            'password_confirmation' => 'ANewPassword!2026',
        ])->assertSessionHasNoErrors();

        $this->post(route('login'), [
            'email' => 'swan@apu.test',
            'password' => 'ANewPassword!2026',
        ])->assertRedirect();

        $this->assertAuthenticated();

        // And they are told to change it, so the administrator does not keep
        // knowing somebody else's password.
        $this->assertTrue($assistant->fresh()->must_change_password);
    }

    public function test_changing_your_own_password_still_works(): void
    {
        // Kept deliberately. It needs the current password and sends nothing.
        $this->assertTrue(Route::has('password.update'));
    }
}
