<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The front door: the application boots, and a stranger lands on sign-in.
 *
 * There is no public landing page (NFR-03), so "/" redirects a guest to the
 * sign-in form rather than rendering anything of its own.
 */
class EntryPointTest extends TestCase
{
    public function test_the_application_boots_and_routes(): void
    {
        $this->get('/')->assertRedirect(route('login'));
    }

    public function test_the_sign_in_page_renders_with_project_branding(): void
    {
        // Breeze's <x-application-logo> was the Laravel logo, so the first
        // screen anyone saw carried the framework's branding rather than the
        // project's. If that ever comes back, this fails.
        $this->get('/login')
            ->assertOk()
            ->assertSee('Atlas NetCare')
            ->assertDontSee('Laracasts')
            ->assertDontSee('Deploy now');
    }
}
