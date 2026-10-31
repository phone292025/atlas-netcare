<?php

namespace Tests\Feature;

use App\Enums\DeviceKind;
use App\Enums\RiskBand;
use App\Enums\UserRole;
use App\Models\AccessPoint;
use App\Models\Inspection;
use App\Models\LabPc;
use App\Models\Location;
use App\Models\NetworkDevice;
use App\Models\TelemetryHourly;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Telemetry\NetworkMonitorService;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoDataSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Demo accounts — one sign-in per role, the administrator included.
 *
 * Without these a fresh install has no account to sign in with, and most of
 * the built screens belong to the engineer and the assistants: there would be
 * no way to open the inspection form or the ticket queue at all.
 *
 * @see database/seeders/DemoDataSeeder.php
 * @see docs/05-data-strategy.md
 */
class DemoDataSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_one_account_per_role(): void
    {
        $this->seed(DemoDataSeeder::class);

        $this->assertSame(1, User::where('role', UserRole::Admin)->count());
        $this->assertSame(1, User::where('role', UserRole::Engineer)->count());
        $this->assertSame(2, User::where('role', UserRole::Assistant)->count());
    }

    public function test_the_default_administrator_signs_in_without_a_forced_change(): void
    {
        $this->seed(DemoDataSeeder::class);

        $admin = User::where('email', config('atlas.first_admin.email'))->firstOrFail();

        $this->assertSame(UserRole::Admin, $admin->role);
        $this->assertTrue($admin->is_demo, 'The purge could not take it away.');
        $this->assertFalse($admin->must_change_password);

        $this->post('/login', [
            'email' => config('atlas.first_admin.email'),
            'password' => config('atlas.demo_users.password'),
        ])->assertRedirect();

        $this->assertAuthenticatedAs($admin);
    }

    public function test_seeding_never_gives_a_real_account_the_demo_password(): void
    {
        /*
         * The real administrator made with atlas:create-admin sits at the same
         * address as the default one. Seeding used to reset whatever account
         * held a demo address, which would have handed the real administrator
         * the public password and made it purgeable.
         */
        Artisan::call('atlas:create-admin');
        preg_match('/Password: (\S+)/', Artisan::output(), $printed);

        $this->seed(DemoDataSeeder::class);

        $admin = User::where('email', config('atlas.first_admin.email'))->firstOrFail();

        $this->assertFalse($admin->is_demo);
        $this->assertTrue($admin->must_change_password);
        $this->assertTrue(Hash::check($printed[1], $admin->password), 'Seeding changed the real administrator\'s password.');
        $this->assertFalse(Hash::check(config('atlas.demo_users.password'), $admin->password));
    }

    public function test_production_gets_no_account_with_the_published_password(): void
    {
        // Not the administrator, and not the engineer or the assistants
        // either: each could sign in with a password printed in the README.
        $this->app['env'] = 'production';

        // --force, as in production db:seed asks first.
        $this->artisan('db:seed', ['--class' => DemoDataSeeder::class, '--force' => true])
            ->expectsOutputToContain('No demo accounts in production')
            ->assertSuccessful();

        $this->assertSame(0, User::count());
    }

    public function test_two_assistants_so_ownership_rules_can_be_demonstrated(): void
    {
        // A single assistant cannot show that an assistant sees only their own
        // inspections and tickets — a rule worth being able to demonstrate
        // rather than assert.
        $this->seed(DemoDataSeeder::class);

        $emails = User::where('role', UserRole::Assistant)->pluck('email');

        $this->assertContains(config('atlas.demo_users.assistant.email'), $emails);
        $this->assertContains(config('atlas.demo_users.assistant_two.email'), $emails);
    }

    public function test_every_demo_account_is_tagged_for_purging(): void
    {
        /*
         * The tag is what makes `atlas:purge-demo-data` able to take these
         * away. An untagged demo account is indistinguishable from a real one
         * and survives the purge into a deployed system.
         */
        $this->seed(DemoDataSeeder::class);

        $this->assertSame(4, User::where('is_demo', true)->count());
    }

    public function test_purging_the_demo_data_keeps_the_administrator(): void
    {
        // Purging demo data must never remove the only way back in.
        $this->artisan('atlas:create-admin')->assertSuccessful();
        $this->seed(DatabaseSeeder::class);

        $this->artisan('atlas:purge-demo-data', ['--force' => true])->assertSuccessful();

        $admin = User::where('email', config('atlas.first_admin.email'))->firstOrFail();

        $this->assertFalse($admin->is_demo);
        $this->assertSame(0, User::where('is_demo', true)->count(), 'The purge left demo accounts behind.');
    }

    public function test_demo_accounts_are_not_forced_to_change_their_password(): void
    {
        // Unlike the first administrator. Being made to change the password
        // before you can look at a screen defeats a demo account.
        $this->seed(DemoDataSeeder::class);

        $engineer = User::where('email', config('atlas.demo_users.engineer.email'))->firstOrFail();

        $this->assertFalse($engineer->must_change_password);
    }

    public function test_running_it_twice_does_not_duplicate_anything(): void
    {
        // Teammates re-run seeders. A unique index violation on the second run
        // is a bad first impression of the project.
        $this->seed(DemoDataSeeder::class);
        $after = User::count();

        $this->seed(DemoDataSeeder::class);

        $this->assertSame($after, User::count());
    }

    public function test_reseeding_resets_a_forgotten_demo_password(): void
    {
        // The supported recovery path: somebody changed a shared account's
        // password, so re-run the seeder rather than going into the database.
        $this->seed(DemoDataSeeder::class);

        $engineer = User::where('email', config('atlas.demo_users.engineer.email'))->firstOrFail();
        $engineer->forceFill(['password' => bcrypt('something-else')])->save();

        $this->seed(DemoDataSeeder::class);

        $this->post('/login', [
            'email' => config('atlas.demo_users.engineer.email'),
            'password' => config('atlas.demo_users.password'),
        ])->assertRedirect();

        $this->assertAuthenticated();
    }

    public function test_no_demo_accounts_exist_when_demo_mode_is_off(): void
    {
        /*
         * The deployable case. ATLAS_DEMO_MODE=false has to leave a system
         * with no account at all until atlas:create-admin makes the real
         * administrator. Shipping shared credentials into a deployment is the
         * failure this split prevents.
         */
        config(['atlas.demo_mode' => false]);

        $this->seed(DatabaseSeeder::class);

        $this->assertSame(0, User::count());
    }

    public function test_each_demo_account_can_actually_sign_in(): void
    {
        // The end-to-end check that matters: after migrate --seed, can a
        // teammate get in as each role?
        $this->seed(DemoDataSeeder::class);

        foreach (['first_admin', 'demo_users.engineer', 'demo_users.assistant', 'demo_users.assistant_two'] as $key) {
            $this->post('/login', [
                'email' => config("atlas.{$key}.email"),
                'password' => config('atlas.demo_users.password'),
            ])->assertRedirect();

            $this->assertAuthenticated();
            $this->post('/logout');
        }
    }

    /*
    |---------------------------------------------------------------------
    | The inventory, added 2026-09-02
    |---------------------------------------------------------------------
    |
    | Until then this seeder made accounts and nothing else, while its own
    | docblock promised assets, telemetry, inspections and tickets. The tests
    | above covered the accounts thoroughly, which is why nobody noticed the
    | rest was missing.
    */

    /** Reference data first: the assets need locations and a checklist. */
    private function seedEverything(): void
    {
        $this->seed(ReferenceSeeder::class);
        $this->seed(DemoDataSeeder::class);
    }

    public function test_no_seeded_asset_escapes_without_its_demo_flag(): void
    {
        /*
         * The regression guard, and it is guarding a real escape. Three
         * untagged lab PCs shipped in the first version of this: `TicketFactory`
         * defaults `ticketable_id` to `LabPc::factory()`, and three tickets
         * were created without naming a subject, so the factory quietly made
         * machines that this seeder never knew about.
         *
         * Being factory-made rather than seeder-made, they carried no is_demo
         * flag — so they would have survived `atlas:purge-demo-data` looking
         * exactly like assets somebody had typed in. Nothing caught it except
         * counting rows after a seed.
         */
        $this->seedEverything();

        $this->assertSame(0, LabPc::where('is_demo', false)->count(), 'A lab PC escaped without is_demo.');
        $this->assertSame(0, NetworkDevice::where('is_demo', false)->count(), 'A network device escaped without is_demo.');
        $this->assertSame(0, Ticket::where('is_demo', false)->count(), 'A ticket escaped without is_demo.');
    }

    public function test_every_access_point_hangs_from_its_rooms_switch(): void
    {
        $this->seedEverything();

        $router = NetworkDevice::firstWhere('code', 'RT-CORE');
        $this->assertSame(DeviceKind::Router, $router?->kind);

        $switches = NetworkDevice::where('kind', DeviceKind::Switch->value)->get();
        $this->assertSame(Location::count(), $switches->count(), 'One switch per room.');
        $this->assertTrue($switches->every(fn (NetworkDevice $switch) => $switch->parent_id === $router->id));

        foreach (AccessPoint::with('parent')->get() as $ap) {
            $this->assertSame(DeviceKind::Switch, $ap->parent?->kind, "{$ap->code} hangs from no switch.");
            $this->assertSame($ap->location_id, $ap->parent->location_id, "{$ap->code} hangs from another room's switch.");
        }
    }

    public function test_reseeding_adds_no_second_set_of_switches(): void
    {
        $this->seedEverything();
        $this->seed(DemoDataSeeder::class);

        $this->assertSame(1, NetworkDevice::where('kind', DeviceKind::Router->value)->count());
        $this->assertSame(Location::count(), NetworkDevice::where('kind', DeviceKind::Switch->value)->count());
    }

    public function test_all_seeded_telemetry_is_marked_simulated(): void
    {
        // `source` is what tells a purge which readings were invented. An
        // unmarked row claims to have come from a real access point.
        $this->seedEverything();

        $this->assertGreaterThan(0, TelemetryHourly::count());
        $this->assertSame(0, TelemetryHourly::where('source', '!=', 'simulated')->count());
    }

    public function test_a_fresh_install_shows_a_full_day_on_the_network_monitor(): void
    {
        /*
         * The Network monitor charts raw readings over the last day, and
         * seeding used to write only hourly rows. A fresh clone opened onto an
         * empty chart until the collector had run for hours.
         */
        $this->seedEverything();

        $activity = app(NetworkMonitorService::class)->snapshot()['activity'];

        // Ten-minute points: a full day is 144.
        $this->assertGreaterThanOrEqual(140, count($activity));
    }

    public function test_the_hourly_summaries_carry_the_degrading_trend(): void
    {
        /*
         * The summaries are derived from the seeded readings, so the trend the
         * High band depends on has to be in the readings. The three degrading
         * access points start the fortnight well inside the latency threshold
         * and end it over.
         */
        $this->seedEverything();

        $degrading = AccessPoint::where('is_demo', true)->orderBy('id')->limit(3)->pluck('id');
        $hours = fn () => TelemetryHourly::whereIn('network_device_id', $degrading);

        $firstDay = (float) $hours()->where('hour_bucket', '<', now()->subDays(13))->avg('avg_latency_ms');
        $lastDay = $hours()->where('hour_bucket', '>=', now()->subDay()->startOfHour());

        $this->assertLessThan(config('atlas.thresholds.latency_ms'), $firstDay);
        $this->assertGreaterThan(0, (clone $lastDay)->count());
        $this->assertGreaterThan(config('atlas.thresholds.latency_ms'), (float) (clone $lastDay)->min('avg_latency_ms'));
    }

    public function test_every_hour_of_the_fortnight_is_built_from_readings(): void
    {
        // Twelve five-minute readings behind every finished hour, and none
        // written as an hour on its own.
        $this->seedEverything();

        $ap = AccessPoint::where('is_demo', true)->orderBy('id')->skip(5)->first();
        $finished = TelemetryHourly::where('network_device_id', $ap->id)
            ->where('hour_bucket', '<', now()->startOfHour());

        $this->assertGreaterThanOrEqual(14 * 24, (clone $finished)->count());
        $this->assertSame([12], (clone $finished)->distinct()->pluck('reading_count')->all());
    }

    public function test_a_fresh_install_is_scored_without_a_second_command(): void
    {
        /*
         * The README's setup ends at `migrate --seed`. Scoring was left to the
         * scheduler's 02:00 run, so a fresh clone showed every asset unscored
         * and the maintenance ranking empty, which is the predictive module
         * looking broken to the first person who opens it.
         */
        $this->seedEverything();

        $this->assertSame(0, LabPc::query()->inService()->whereNull('risk_band')->count(), 'A lab PC was left unscored.');
        $this->assertSame(0, AccessPoint::query()->whereNull('risk_band')->count(), 'An access point was left unscored.');
    }

    public function test_it_produces_assets_in_every_risk_band(): void
    {
        /*
         * Why the cohorts are designed rather than random: with random ages
         * every asset scored Low or Medium, and the maintenance ranking had
         * nothing at the top.
         */
        $this->seedEverything();

        $this->artisan('atlas:recalculate-risk', ['--show' => 0])->assertExitCode(0);

        foreach (RiskBand::cases() as $band) {
            $count = LabPc::where('risk_band', $band->value)->count()
                + AccessPoint::where('risk_band', $band->value)->count();

            $this->assertGreaterThan(0, $count, "No asset scored {$band->value}.");
        }
    }

    public function test_the_worst_machine_is_worst_for_a_readable_reason(): void
    {
        // A bare score is not analytics. If the top of the ranking cannot say
        // why, ADR-0007 chose a weighted model over a trained one for nothing.
        $this->seedEverything();
        $this->artisan('atlas:recalculate-risk', ['--show' => 0]);

        $worst = LabPc::orderByDesc('risk_score')->first();

        $this->assertSame(RiskBand::Critical, $worst->risk_band);
        $this->assertArrayHasKey('age', $worst->latestRiskAssessment->factors);
        $this->assertArrayHasKey('fault_frequency', $worst->latestRiskAssessment->factors);
    }

    public function test_it_seeds_history_for_the_reports_to_aggregate(): void
    {
        // Five reports over an empty database all correctly say "nothing yet",
        // and show nothing. They need resolved tickets, open ones, and
        // completed inspections to have anything to aggregate.
        $this->seedEverything();

        $this->assertGreaterThan(0, Inspection::whereNotNull('completed_at')->count());
        $this->assertGreaterThan(0, Ticket::whereNotNull('resolved_at')->count());
        $this->assertGreaterThan(0, Ticket::whereNull('resolved_at')->count());
    }

    public function test_reseeding_does_not_double_the_inventory(): void
    {
        // Same reasoning as the account test above, but the assets are the
        // expensive half: a second run adding eighty more machines would be
        // both wrong and slow.
        $this->seedEverything();
        $before = LabPc::count();

        $this->seed(DemoDataSeeder::class);

        $this->assertSame($before, LabPc::count());
    }
}
