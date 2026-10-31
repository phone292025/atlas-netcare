<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The error pages are screens too.
 *
 * They replace Laravel's stock pages, which carried the framework's own
 * styling rather than the system's.
 *
 * These render through their own shell, not layouts/app.blade.php, because
 * that layout reads the signed-in user for the sidebar and the notification
 * count, and an error page must render even when that is the thing that broke.
 */
class ErrorPagesTest extends TestCase
{
    use RefreshDatabase;

    private function assistant(): User
    {
        $user = User::factory()->create();
        $user->forceFill(['role' => UserRole::Assistant, 'is_active' => true])->save();

        return $user->fresh();
    }

    public function test_a_missing_page_is_branded_and_offers_a_guest_the_sign_in(): void
    {
        $this->get('/no-such-page')
            ->assertNotFound()
            ->assertSee('That page does not exist')
            ->assertSee(route('home'), escape: false)
            ->assertDontSee('NOT FOUND');
    }

    public function test_a_missing_page_offers_a_signed_in_user_their_home(): void
    {
        $this->actingAs($this->assistant())
            ->get('/no-such-page')
            ->assertNotFound()
            ->assertSee('Go to your home screen')
            ->assertSee(route('home'), escape: false);
    }

    public function test_a_forbidden_area_explains_itself_in_the_middlewares_own_words(): void
    {
        $this->actingAs($this->assistant())
            ->get(route('admin.users.index'))
            ->assertForbidden()
            ->assertSee('You do not have access to this area')
            ->assertSee('Your sidebar lists everything you can reach')
            ->assertDontSee('FORBIDDEN');
    }

    public function test_the_error_shell_does_not_depend_on_the_signed_in_layout(): void
    {
        // No sidebar, no sign-out button: the shell must not need a user.
        $this->get('/no-such-page')
            ->assertNotFound()
            ->assertSee(config('app.name'))
            ->assertDontSee('Sign out');
    }
}
