<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Location;
use App\Models\ShiftTemplate;
use App\Models\User;
use App\Services\Shifts\ShiftService;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * ReferenceSeeder, the data every fresh install gets from `db:seed`.
 *
 * The campus locations, the checklist and the shift patterns. It creates no
 * account. The first administrator comes from `atlas:create-admin`.
 *
 * @see docs/05-data-strategy.md — Mechanism 2
 */
class ReferenceSeederTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_it_creates_no_account_and_says_how_to_create_the_administrator(): void
    {
        /*
         * It used to create the first administrator from a starting password
         * published in .env.example, so anyone who read it could sign in
         * first on a copy they could reach. `atlas:create-admin` does that
         * now, with a password it shows once.
         */
        $this->artisan('db:seed', ['--class' => ReferenceSeeder::class])
            ->expectsOutputToContain('php artisan atlas:create-admin')
            ->assertSuccessful();

        $this->assertSame(0, User::count());
    }

    public function test_it_leaves_an_existing_administrator_exactly_as_it_is(): void
    {
        // `db:seed` is routine. It must never reset, re-flag or demote the
        // highest-privilege account behind somebody's back.
        $admin = User::factory()->create(['password' => Hash::make('the-one-they-chose')]);
        $admin->forceFill(['role' => UserRole::Admin, 'is_active' => true, 'must_change_password' => false])->save();
        $before = $admin->fresh()->only(['password', 'role', 'is_active', 'must_change_password', 'is_demo']);

        $this->artisan('db:seed', ['--class' => ReferenceSeeder::class])
            ->doesntExpectOutputToContain('atlas:create-admin')
            ->assertSuccessful();

        $this->assertSame($before, $admin->fresh()->only(['password', 'role', 'is_active', 'must_change_password', 'is_demo']));
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

        // The locations are still there, which is the whole point of
        // splitting the seeders. No account is: atlas:create-admin makes the
        // first one.
        $this->assertSame(0, User::count());
        $this->assertGreaterThanOrEqual(6, Location::count());
    }

    public function test_it_seeds_a_week_of_shift_patterns(): void
    {
        /*
         * These were deferred in Sprint 0 with a comment saying they belonged
         * in this seeder once the table existed. M6 created the table and the
         * comment was never acted on, so a fresh install had NO templates:
         * pressing "Generate this week" produced nothing at all, and the
         * roster looked broken when it was only empty.
         */
        $this->seed(ReferenceSeeder::class);

        $templates = ShiftTemplate::where('is_active', true)->get();

        $this->assertGreaterThanOrEqual(5, $templates->count());

        // Monday to Friday, so a generated week fills a grid rather than one
        // row stranded in seven columns.
        $this->assertGreaterThanOrEqual(
            5,
            $templates->pluck('day_of_week')->unique()->count(),
            'The patterns should cover at least five different days.',
        );
    }

    public function test_a_generated_week_covers_several_days(): void
    {
        // The end of the same problem: patterns exist, so generation produces
        // a week somebody would recognise as a roster.
        $this->seed(ReferenceSeeder::class);

        $assistant = User::factory()->create();
        $assistant->forceFill([
            'role' => UserRole::Assistant,
            'is_active' => true,
        ])->save();

        $created = app(ShiftService::class)
            ->generateWeek(Carbon::now()->startOfWeek()->addWeek());

        $this->assertGreaterThanOrEqual(5, $created->count());
        $this->assertGreaterThanOrEqual(
            5,
            $created->pluck('shift_date')->unique()->count(),
            'A generated week should touch at least five separate days.',
        );
    }

    public function test_seeding_the_patterns_twice_changes_nothing(): void
    {
        // Keyed on name. A second run must not double the roster patterns.
        $this->seed(ReferenceSeeder::class);
        $before = ShiftTemplate::count();

        $this->seed(ReferenceSeeder::class);

        $this->assertSame($before, ShiftTemplate::count());
    }

    public function test_seeding_again_keeps_what_an_administrator_changed(): void
    {
        // The README says to seed again to reset a demo password. That run
        // must not undo an edited location or switch a pattern back on.
        $this->seed(ReferenceSeeder::class);
        $location = Location::query()->where('code', 'LIB-L3')->sole();
        $location->update(['name' => 'Library, renamed']);
        $pattern = ShiftTemplate::query()->first();
        $pattern->update(['is_active' => false]);

        $this->seed(ReferenceSeeder::class);

        $this->assertSame('Library, renamed', $location->fresh()->name);
        $this->assertFalse($pattern->fresh()->is_active);
    }
}
