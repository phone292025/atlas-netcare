<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Location;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ReferenceSeeder is what every teammate runs on their first
 * `php artisan migrate --seed`, and what a marker runs from the Installation
 * Guide. If it is wrong, nobody can sign in.
 *
 * @see docs/07-data-strategy.md — Mechanism 2
 */
class ReferenceSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_the_first_administrator(): void
    {
        // Atlas NetCare has no self-registration, so somebody has to create
        // the first account or the system is unreachable (FR-105).
        $this->seed(ReferenceSeeder::class);

        $admin = User::where('email', config('atlas.first_admin.email'))->firstOrFail();

        $this->assertSame(UserRole::Admin, $admin->role);
        $this->assertTrue($admin->is_active);
        $this->assertFalse($admin->is_demo);
    }

    public function test_the_first_administrator_is_flagged_to_change_its_password(): void
    {
        // The seeded password comes from the environment and is shared by
        // whoever set it up. It must not stay in use.
        $this->seed(ReferenceSeeder::class);

        $admin = User::where('email', config('atlas.first_admin.email'))->firstOrFail();

        $this->assertTrue($admin->must_change_password);
    }

    public function test_the_administrator_password_is_hashed(): void
    {
        $this->seed(ReferenceSeeder::class);

        $admin = User::where('email', config('atlas.first_admin.email'))->firstOrFail();

        $this->assertNotSame(config('atlas.first_admin.password'), $admin->password);
        $this->assertTrue(password_verify(config('atlas.first_admin.password'), $admin->password));
    }

    public function test_it_creates_the_campus_locations_from_the_approved_scope(): void
    {
        // The proposal scopes monitoring to the Library, the IT Labs and the
        // Campus Cafeteria. All three must exist out of the box.
        $this->seed(ReferenceSeeder::class);

        $this->assertGreaterThanOrEqual(6, Location::count());

        foreach (['it_lab', 'library', 'cafeteria'] as $type) {
            $this->assertTrue(
                Location::where('type', $type)->exists(),
                "No location of type '{$type}' was seeded."
            );
        }
    }

    public function test_nothing_it_creates_is_tagged_as_demo_data(): void
    {
        // Reference data must survive `atlas:purge-demo-data`. A seeded admin
        // that vanished with the demo data would lock everyone out.
        $this->seed(ReferenceSeeder::class);

        $this->assertSame(0, User::where('is_demo', true)->count());
    }

    public function test_running_it_twice_does_not_duplicate_anything(): void
    {
        // Teammates re-run seeders. A unique index violation on the second run
        // is a bad first impression of the project.
        $this->seed(ReferenceSeeder::class);
        $usersAfterFirst = User::count();
        $locationsAfterFirst = Location::count();

        $this->seed(ReferenceSeeder::class);

        $this->assertSame($usersAfterFirst, User::count());
        $this->assertSame($locationsAfterFirst, Location::count());
    }

    public function test_demo_data_is_skipped_when_demo_mode_is_off(): void
    {
        config(['atlas.demo_mode' => false]);

        $this->seed(DatabaseSeeder::class);

        // The administrator and the locations are still there — that is the
        // whole point of splitting the seeders.
        $this->assertSame(1, User::count());
        $this->assertGreaterThanOrEqual(6, Location::count());
    }

    public function test_the_administrator_can_actually_sign_in(): void
    {
        // The end-to-end check that matters: after migrate --seed, can a
        // human get in?
        $this->seed(ReferenceSeeder::class);

        $this->post('/login', [
            'email' => config('atlas.first_admin.email'),
            'password' => config('atlas.first_admin.password'),
        ])->assertRedirect();

        $this->assertAuthenticated();
    }
}
