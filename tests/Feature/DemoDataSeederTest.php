<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Demo accounts — one sign-in per role.
 *
 * Without these the only account on a fresh install is the administrator, and
 * most of the built screens belong to the other two roles: there would be no
 * way to open the inspection form or the ticket queue at all.
 *
 * @see database/seeders/DemoDataSeeder.php
 * @see docs/07-data-strategy.md
 */
class DemoDataSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_one_account_per_role(): void
    {
        $this->seed(DemoDataSeeder::class);

        $this->assertSame(1, User::where('role', UserRole::Engineer)->count());
        $this->assertSame(2, User::where('role', UserRole::Assistant)->count());
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

        $this->assertSame(3, User::where('is_demo', true)->count());
    }

    public function test_the_administrator_is_not_tagged_as_demo(): void
    {
        // Purging demo data must never remove the only way back in.
        $this->seed(DatabaseSeeder::class);

        $admin = User::where('email', config('atlas.first_admin.email'))->firstOrFail();

        $this->assertFalse($admin->is_demo);
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
         * whose only account is the real administrator — shipping shared
         * credentials into a deployment is the failure this split prevents.
         */
        config(['atlas.demo_mode' => false]);

        $this->seed(DatabaseSeeder::class);

        $this->assertSame(1, User::count());
        $this->assertSame(0, User::where('is_demo', true)->count());
    }

    public function test_each_demo_account_can_actually_sign_in(): void
    {
        // The end-to-end check that matters: after migrate --seed, can a
        // teammate get in as each role?
        $this->seed(DemoDataSeeder::class);

        foreach (['engineer', 'assistant', 'assistant_two'] as $key) {
            $this->post('/login', [
                'email' => config("atlas.demo_users.{$key}.email"),
                'password' => config('atlas.demo_users.password'),
            ])->assertRedirect();

            $this->assertAuthenticated();
            $this->post('/logout');
        }
    }
}
