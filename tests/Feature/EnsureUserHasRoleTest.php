<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Http\Middleware\EnsureUserHasRole;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Area-level authorisation is the boundary between the three roles, so it is
 * worth testing the middleware directly rather than only through the screens
 * that happen to use it.
 *
 * These tests define their own routes so they stay valid as real routes arrive
 * with their modules.
 *
 * Note: `users.role` and `users.is_active` do not exist as columns yet — they
 * arrive with the M1 migration in Stage 4. Until then the role is set as an
 * in-memory attribute, which is enough to exercise the middleware.
 *
 * @see docs/03-requirements.md FR-104, FR-106
 */
class EnsureUserHasRoleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['web', 'auth', 'role:engineer'])
            ->get('/test-engineer-only', fn () => 'reached')
            ->name('test.engineer');

        Route::middleware(['web', 'auth', 'role:engineer,admin'])
            ->get('/test-engineer-or-admin', fn () => 'reached')
            ->name('test.engineerOrAdmin');
    }

    private function userWithRole(?UserRole $role, bool $active = true): User
    {
        $user = User::factory()->create();

        // Set in memory; the columns land with the M1 migration.
        $user->role = $role;
        $user->is_active = $active;

        return $user;
    }

    /**
     * A user whose `role` and `is_active` come back out of the database rather
     * than being set in memory, so the values have the types Eloquent actually
     * hands back when no cast is configured.
     *
     * The columns are added here because the M1 migration has not landed yet.
     * Once it has, drop the Schema::table call and use the real columns.
     */
    private function userFromDatabase(string $role, bool $isActive): User
    {
        if (! Schema::hasColumn('users', 'role')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->string('role')->nullable();
                $table->boolean('is_active')->default(true);
            });
        }

        // forceFill, not the factory: neither column is in User::$fillable, so
        // passing them to create() would silently drop them.
        $user = User::factory()->create();
        $user->forceFill(['role' => $role, 'is_active' => $isActive])->save();

        return User::findOrFail($user->getKey());
    }

    public function test_the_role_alias_is_registered_under_the_expected_name(): void
    {
        // Aliases are pushed to the router when the HTTP kernel boots, so make
        // a request first. This pins the alias NAME: renaming it in
        // bootstrap/app.php would break every route group in the system.
        $this->actingAs($this->userWithRole(UserRole::Engineer))->get('/test-engineer-only');

        $aliases = app('router')->getMiddleware();

        $this->assertArrayHasKey('role', $aliases);
        $this->assertSame(EnsureUserHasRole::class, $aliases['role']);
    }

    public function test_a_matching_role_reaches_the_route(): void
    {
        $this->actingAs($this->userWithRole(UserRole::Engineer))
            ->get('/test-engineer-only')
            ->assertOk()
            ->assertSee('reached');
    }

    public function test_a_non_matching_role_is_forbidden(): void
    {
        $this->actingAs($this->userWithRole(UserRole::Assistant))
            ->get('/test-engineer-only')
            ->assertForbidden();
    }

    public function test_any_listed_role_is_accepted(): void
    {
        foreach ([UserRole::Engineer, UserRole::Admin] as $role) {
            $this->actingAs($this->userWithRole($role))
                ->get('/test-engineer-or-admin')
                ->assertOk();
        }
    }

    public function test_a_role_outside_the_list_is_still_forbidden(): void
    {
        $this->actingAs($this->userWithRole(UserRole::Assistant))
            ->get('/test-engineer-or-admin')
            ->assertForbidden();
    }

    public function test_a_user_with_no_role_is_forbidden(): void
    {
        $this->actingAs($this->userWithRole(null))
            ->get('/test-engineer-only')
            ->assertForbidden();
    }

    public function test_a_deactivated_user_is_forbidden_even_with_the_right_role(): void
    {
        // FR-106: deactivation removes access but preserves history.
        $this->actingAs($this->userWithRole(UserRole::Engineer, active: false))
            ->get('/test-engineer-only')
            ->assertForbidden();
    }

    public function test_a_guest_is_redirected_to_login_by_the_auth_middleware(): void
    {
        // The role middleware must not be what stops unauthenticated requests;
        // `auth` handles them first and redirects rather than returning 403.
        $this->get('/test-engineer-only')->assertRedirect('/login');
    }

    public function test_a_deactivated_user_is_forbidden_when_is_active_is_a_real_database_column(): void
    {
        // Regression test for a guard that failed OPEN.
        //
        // The in-memory tests above keep passing even when the guard is broken,
        // because assigning PHP false makes a strict `=== false` comparison
        // work. The bug only appeared when the value came back out of the
        // database, where an UNCAST boolean column reads as int(0) and
        // `=== false` never matches.
        //
        // Stage 4 added `'is_active' => 'boolean'` to the User model, so the
        // value is now a real bool. The guard is deliberately still a loose
        // truthiness check rather than `=== false`: if the cast is ever dropped
        // the middleware keeps working. This test loads the user FROM the
        // database so it exercises whichever of the two is true.
        $user = $this->userFromDatabase(role: 'engineer', isActive: false);

        $this->assertFalse((bool) $user->is_active, 'A deactivated user must read back as falsy.');

        $this->actingAs($user)
            ->get('/test-engineer-only')
            ->assertForbidden();
    }

    public function test_the_user_model_casts_is_active_to_a_real_boolean(): void
    {
        // Pins the cast added in Stage 4. Without it Eloquent returns int(0),
        // which is exactly what let the guard fail open in the first place.
        $user = $this->userFromDatabase(role: 'engineer', isActive: false);

        $this->assertIsBool($user->is_active);
        $this->assertFalse($user->is_active);
    }

    public function test_an_active_user_with_a_real_database_column_is_allowed(): void
    {
        $user = $this->userFromDatabase(role: 'engineer', isActive: true);

        $this->assertTrue((bool) $user->is_active);

        $this->actingAs($user)->get('/test-engineer-only')->assertOk();
    }

    public function test_a_plain_string_role_is_accepted_as_well_as_the_enum(): void
    {
        // The middleware tolerates a raw string so it is not coupled to the
        // model cast being configured.
        $user = User::factory()->create();
        $user->role = 'engineer';
        $user->is_active = true;

        $this->actingAs($user)->get('/test-engineer-only')->assertOk();
    }
}
