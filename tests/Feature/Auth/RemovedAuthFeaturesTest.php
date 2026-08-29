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
            // Emailed a reset link to an address that cannot receive mail,
            // and said so on screen while writing the link into a log file.
            'password reset request' => ['password.request'],
            'password reset email' => ['password.email'],
            'password reset form' => ['password.reset'],
            'password reset submit' => ['password.store'],

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

    public function test_the_reset_urls_are_gone_not_merely_unlinked(): void
    {
        // Removing the link from the login page would leave the endpoint
        // reachable by anybody who typed the address.
        $this->get('/forgot-password')->assertNotFound();
        $this->post('/forgot-password', ['email' => 'swan@apu.test'])->assertNotFound();
        $this->get('/reset-password/any-token')->assertNotFound();
    }

    public function test_the_login_page_does_not_offer_a_reset(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertDontSee('Forgot your password')
            // What it says instead: the recovery path that actually exists.
            ->assertSee('Accounts are created by a system administrator');
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
