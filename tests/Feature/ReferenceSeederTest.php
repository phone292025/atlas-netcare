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
 * ReferenceSeeder is what every fresh install runs through
 * `php artisan migrate --seed`. If it is wrong, nobody can sign in.
 *
 * @see docs/05-data-strategy.md — Mechanism 2
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

    /*
    |---------------------------------------------------------------------
    | Re-seeding must not undo the administrator, added 2026-09-04
    |---------------------------------------------------------------------
    |
    | `test_running_it_twice_does_not_duplicate_anything` above counts ROWS,
    | and the row count never changed — so it could not see that every run was
    | overwriting the administrator's password and re-raising the
    | must_change_password flag.
    */

    public function test_reseeding_does_not_overwrite_a_password_the_admin_chose(): void
    {
        /*
         * `db:seed` is routine: the README tells people to run it and
         * DemoDataSeeder depends on it. Silently resetting the highest
         * privilege account's password back to whatever `.env` still says is
         * not something a seeder should do behind somebody's back.
         */
        $this->seed(ReferenceSeeder::class);

        $admin = User::where('email', config('atlas.first_admin.email'))->firstOrFail();
        $admin->forceFill(['password' => Hash::make('the-one-they-chose')])->save();
        $chosen = $admin->fresh()->password;

        $this->seed(ReferenceSeeder::class);

        $this->assertSame($chosen, $admin->fresh()->password, 'Re-seeding reset the chosen password.');
        $this->assertTrue(Hash::check('the-one-they-chose', $admin->fresh()->password));
    }

    public function test_reseeding_does_not_lock_the_admin_back_onto_the_change_screen(): void
    {
        /*
         * The half that only started to hurt once EnsurePasswordIsChanged
         * existed. Before the middleware, re-raising the flag did nothing
         * visible. Now it bounces the administrator to the profile screen from
         * every other page — after they had already done what it asked.
         */
        $this->seed(ReferenceSeeder::class);

        $admin = User::where('email', config('atlas.first_admin.email'))->firstOrFail();
        $admin->forceFill(['must_change_password' => false])->save();

        $this->seed(ReferenceSeeder::class);

        $this->assertFalse($admin->fresh()->must_change_password, 'Re-seeding re-flagged the account.');
    }

    public function test_reseeding_does_restore_a_locked_out_administrator(): void
    {
        /*
         * The other side of it, and the reason this is not simply "skip the
         * row if it exists". Deactivating or archiving the only administrator
         * is exactly the mistake somebody makes at 2am, and re-seeding is the
         * documented way back in. Access is restored; the password is not
         * touched.
         */
        $this->seed(ReferenceSeeder::class);

        $admin = User::where('email', config('atlas.first_admin.email'))->firstOrFail();
        $admin->forceFill(['is_active' => false, 'role' => UserRole::Assistant])->save();
        $admin->delete();

        $this->seed(ReferenceSeeder::class);

        $restored = User::withTrashed()->where('email', config('atlas.first_admin.email'))->firstOrFail();

        $this->assertTrue($restored->is_active);
        $this->assertSame(UserRole::Admin, $restored->role);
        $this->assertNull($restored->deleted_at);
    }

    public function test_the_published_administrator_password_is_documented_and_only_a_start(): void
    {
        /*
         * Since 2026-09-25 .env.example ships a starting password, so a fresh
         * clone can sign in. That is only safe because the account must
         * choose its own at first sign-in, and only useful if the README and
         * the guide name the same value: in August the file and its own
         * documentation disagreed.
         */
        preg_match('/^ATLAS_ADMIN_PASSWORD=(.+)$/m', file_get_contents(base_path('.env.example')), $match);
        $published = trim($match[1] ?? '');

        $this->assertNotSame('', $published, '.env.example no longer ships a starting password.');
        $this->assertStringContainsString("`{$published}`", file_get_contents(base_path('README.md')), 'The README names a different administrator password.');
        // The guide is not in the submitted copy, which leaves docs/ out.
        if (is_file($guide = base_path('docs/11-installation-guide.md'))) {
            $this->assertStringContainsString("`{$published}`", file_get_contents($guide), 'The install guide names a different administrator password.');
        }

        // Still no fallback in config: an emptied .env must refuse, not quietly use something.
        $this->assertMatchesRegularExpression(
            "/'password' => env\('ATLAS_ADMIN_PASSWORD'\),/",
            file_get_contents(config_path('atlas.php')),
            'config/atlas.php gives the administrator password a fallback.',
        );

        config(['atlas.first_admin.password' => $published]);
        $this->seed(ReferenceSeeder::class);
        $admin = User::where('email', config('atlas.first_admin.email'))->firstOrFail();

        $this->assertTrue(Hash::check($published, $admin->password));
        $this->assertTrue($admin->must_change_password, 'The published password must be replaced at first sign-in.');
    }

    public function test_the_seeder_refuses_rather_than_using_a_known_password(): void
    {
        config(['atlas.first_admin.password' => null]);

        $this->artisan('db:seed', ['--class' => ReferenceSeeder::class])
            ->expectsOutputToContain('ATLAS_ADMIN_PASSWORD is not set');

        $this->assertSame(0, User::where('role', UserRole::Admin)->count());
    }
}
