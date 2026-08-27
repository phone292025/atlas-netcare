<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The smallest possible check that the application boots and routes.
 *
 * Laravel's stock version asserted that "/" returns 200, which stopped being
 * true when the welcome page was removed: Atlas NetCare has no public landing
 * page, so "/" redirects a guest to sign in.
 */
class ExampleTest extends TestCase
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
