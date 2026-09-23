<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sign-in and profile form errors are announced with the failing field.
 *
 * These screens use <x-form.*>, like every other form. Breeze's own form
 * components rendered errors with no programmatic link to their input, so a
 * screen reader user heard nothing about a failure, and a scan here keeps them
 * from coming back.
 *
 * @see docs/01-requirements.md NFR-10
 */
class AuthFormAccessibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_login_error_is_announced_with_the_field_that_failed(): void
    {
        $this->from('/login')
            ->post('/login', ['email' => 'nobody@example.com', 'password' => 'wrong'])
            ->assertRedirect('/login');

        $html = $this->get('/login')->getContent();

        $this->assertStringContainsString('aria-invalid="true"', $html);
        $this->assertStringContainsString('aria-describedby="email-error"', $html);
        $this->assertStringContainsString('id="email-error"', $html);
    }

    public function test_a_rejected_login_returns_the_email_the_user_typed(): void
    {
        $this->from('/login')->post('/login', [
            'email' => 'typed@example.com',
            'password' => 'wrong',
        ]);

        $this->get('/login')
            ->assertOk()
            ->assertSee('value="typed@example.com"', escape: false);
    }

    public function test_the_password_form_finds_its_errors_in_its_own_named_bag(): void
    {
        // Two forms share the profile page, so the password form validates into
        // the `updatePassword` bag. Without the bag prop the fields would look
        // in the default bag and silently render no error at all — the exact
        // regression this migration could have introduced.
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from('/profile')
            ->put('/password', [
                'current_password' => 'not-the-right-one',
                'password' => 'new-password-here',
                'password_confirmation' => 'new-password-here',
            ])
            ->assertSessionHasErrors('current_password', errorBag: 'updatePassword');

        $html = $this->actingAs($user)->get('/profile')->getContent();

        $this->assertStringContainsString('id="current-password-error"', $html);
        $this->assertStringContainsString('aria-describedby="current-password-error"', $html);
    }

    public function test_the_profile_form_errors_stay_in_the_default_bag(): void
    {
        // The other form on the same page must not pick up the password form's
        // errors, and vice versa.
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from('/profile')
            ->patch('/profile', ['name' => '', 'email' => 'not-an-email']);

        $html = $this->actingAs($user)->get('/profile')->getContent();

        $this->assertStringContainsString('id="name-error"', $html);
        $this->assertStringNotContainsString('id="current-password-error"', $html);
    }

    public function test_no_breeze_form_component_survives_anywhere(): void
    {
        // If one comes back, the system has two form vocabularies again and the
        // accessibility wiring quietly stops being universal.
        foreach (glob(dirname(__DIR__, 2).'/resources/views/**/*.blade.php') as $file) {
            $contents = file_get_contents($file);

            foreach (['<x-text-input', '<x-input-label', '<x-input-error', '<x-primary-button'] as $legacy) {
                $this->assertStringNotContainsString($legacy, $contents, basename($file).' still uses '.$legacy);
            }
        }
    }
}
