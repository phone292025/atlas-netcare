<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The shell is the one thing every screen in the system renders inside, so a
 * fault here shows up on all of them at once.
 *
 * Module routes arrive over Sprints 1-4. Two of them are stubbed in setUp so
 * both halves of the "route exists / does not exist yet" behaviour can be
 * exercised: `admin.users.index` and `engineer.dashboard` are registered,
 * everything else in the navigation map is not.
 *
 * Registering a route in a test needs one extra step: `->name()` is applied
 * after the route has already entered the collection, so the name lookup table
 * is stale and `Route::has()` returns false. `refreshNameLookups()` rebuilds
 * it. The route itself works either way — only lookup by name is affected.
 *
 * @see docs/08-ui-conventions.md
 */
class AppShellTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // admin.users.index is no longer stubbed here — M1 built the real one.
        // As each module lands, delete its stub rather than shadowing the real
        // route with a fake that can drift away from it.
        Route::middleware(['web', 'auth'])->get('/test-engineer-dash', fn () => 'dash')->name('engineer.dashboard');

        // ->name() is applied after the route enters the collection, so the
        // name lookup table is stale until it is refreshed. Without this,
        // Route::has() returns false for routes registered in a test.
        Route::getRoutes()->refreshNameLookups();
    }

    private function userWithRole(UserRole $role): User
    {
        if (! Schema::hasColumn('users', 'role')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->string('role')->nullable();
                $table->boolean('is_active')->default(true);
            });
        }

        $user = User::factory()->create();
        $user->forceFill(['role' => $role->value, 'is_active' => true])->save();

        // The model cast lands with the M1 migration; set the enum in memory so
        // the shell sees what it will see in production.
        $user = User::findOrFail($user->getKey());
        $user->role = $role;

        return $user;
    }

    public function test_the_shell_renders_for_a_signed_in_user(): void
    {
        $this->actingAs($this->userWithRole(UserRole::Engineer))
            ->get('/profile')
            ->assertOk()
            ->assertSee('Atlas NetCare')
            ->assertSee('Profile');
    }

    public function test_the_page_title_comes_from_the_title_slot(): void
    {
        $this->actingAs($this->userWithRole(UserRole::Admin))
            ->get('/profile')
            ->assertOk()
            ->assertSee('<title>Profile · Atlas NetCare</title>', escape: false);
    }

    public function test_the_signed_in_users_role_is_shown(): void
    {
        $this->actingAs($this->userWithRole(UserRole::Assistant))
            ->get('/profile')
            ->assertOk()
            ->assertSee('Student IT Assistant');
    }

    public function test_a_user_with_no_role_does_not_break_the_shell(): void
    {
        // Until the M1 migration lands, every real user has a null role.
        $this->actingAs(User::factory()->create())
            ->get('/profile')
            ->assertOk()
            ->assertSee('No role assigned');
    }

    public function test_each_role_sees_only_its_own_navigation(): void
    {
        $this->actingAs($this->userWithRole(UserRole::Admin))
            ->get('/profile')
            ->assertSee('Users')
            ->assertDontSee('Ticket Queue');

        $this->actingAs($this->userWithRole(UserRole::Engineer))
            ->get('/profile')
            ->assertSee('Dashboard')
            ->assertDontSee('Audit Log');
    }

    public function test_a_registered_navigation_route_renders_as_a_link(): void
    {
        // admin.users.index is a real route now, which is exactly what this
        // should assert against — the sidebar reaching the module M1 built.
        $this->actingAs($this->userWithRole(UserRole::Admin))
            ->get('/profile')
            ->assertSee('href="'.route('admin.users.index').'"', escape: false);
    }

    public function test_an_unregistered_navigation_route_is_flagged_rather_than_hidden_in_debug(): void
    {
        // A silently vanishing menu item is how a mistyped route name costs
        // somebody an afternoon. In debug it must be visible.
        config(['app.debug' => true]);

        $this->actingAs($this->userWithRole(UserRole::Admin))
            ->get('/profile')
            ->assertSee('is not registered yet', escape: false);
    }

    public function test_an_unregistered_navigation_route_is_hidden_when_debug_is_off(): void
    {
        config(['app.debug' => false]);

        $this->actingAs($this->userWithRole(UserRole::Admin))
            ->get('/profile')
            ->assertDontSee('is not registered yet', escape: false);
    }

    public function test_the_sidebar_transform_is_scoped_below_the_desktop_breakpoint(): void
    {
        // Regression guard for a layout bug that 120 passing tests missed:
        // written as plain `-translate-x-full` with `lg:translate-x-0`, both
        // utilities set --tw-translate-x and the negative one won the cascade,
        // so the sidebar sat off-screen on every desktop screen with a 256px
        // empty gutter beside it.
        //
        // A rendered-HTML test cannot evaluate CSS, so this pins the class
        // names instead. If someone "simplifies" the variant away, this fails.
        $html = $this->actingAs($this->userWithRole(UserRole::Admin))
            ->get('/profile')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('max-lg:-translate-x-full', $html);
        $this->assertStringContainsString('max-lg:translate-x-0', $html);
        $this->assertStringNotContainsString('lg:translate-x-0"', $html, 'An unscoped lg:translate-x-0 re-creates the cascade conflict.');
    }

    public function test_the_root_url_redirects_a_signed_in_user_to_their_home(): void
    {
        $this->actingAs($this->userWithRole(UserRole::Engineer))
            ->get('/')
            ->assertRedirect(route('home'));
    }

    public function test_the_root_url_sends_a_guest_to_sign_in(): void
    {
        // There is no public landing page. Atlas NetCare is internal and
        // privilege-based with no student-facing entry point (NFR-03), so a
        // guest at "/" gets the sign-in form rather than a marketing page.
        $this->get('/')->assertRedirect(route('login'));
    }

    public function test_dashboard_redirect_uses_the_role_home_route_when_it_exists(): void
    {
        $this->actingAs($this->userWithRole(UserRole::Engineer))
            ->get('/home')
            ->assertRedirect(route('engineer.dashboard'));
    }

    public function test_dashboard_redirect_falls_back_to_profile_when_the_target_does_not_exist(): void
    {
        // assistant.shifts.today arrives with M6. Until then this controller
        // must not be the reason the application is unreachable.
        $this->actingAs($this->userWithRole(UserRole::Assistant))
            ->get('/home')
            ->assertRedirect(route('profile.edit'));
    }

    public function test_a_user_with_no_role_is_sent_somewhere_safe(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/home')
            ->assertRedirect(route('profile.edit'));
    }
}
