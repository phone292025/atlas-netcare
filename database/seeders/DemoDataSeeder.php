<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Everything needed to demonstrate the system, and nothing the system needs to
 * run.
 *
 * ONLY RUNS when config('atlas.demo_mode') is true. Every row it creates is
 * tagged is_demo (or, for telemetry, source = 'simulated'), so
 * `php artisan atlas:purge-demo-data` can remove all of it and leave the
 * reference data and anything a real user typed untouched.
 *
 * That tagging is not optional. A row seeded here without its flag becomes
 * indistinguishable from real data and survives the purge.
 *
 * Still to come with their modules:
 *
 *   M2  ~250 lab PCs and ~40 access points
 *   M3  completed inspections with a spread of outcomes
 *   M4  tickets across every state
 *   M5  90 days of simulated telemetry
 *   M6  shift templates and rostered shifts
 *
 * Use factories for those, not explicit records — the same factories power the
 * feature tests, so there is one set of fixtures rather than two.
 *
 * @see docs/07-data-strategy.md — Mechanism 2
 * @see docs/decisions/ADR-0003-single-database-demo-flag.md
 */
class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        $this->demoAccounts();

        $this->command?->info('  Demo assets and telemetry arrive with M2 to M6.');
    }

    /**
     * One sign-in per role.
     *
     * Without these the only account on a fresh install is the administrator,
     * and four of the seven built screens belong to the other two roles —
     * there is no way to open the inspection form or the ticket queue at all.
     *
     * Written out here rather than through UserFactory because these are
     * fixed, quotable credentials that go in the README and get handed to a
     * marker. A factory's randomness is the opposite of what is wanted.
     *
     * updateOrCreate keyed on email, so re-running the seeder resets the
     * password rather than failing on the unique index — which is also the
     * supported way to recover a demo account whose password has been changed
     * or forgotten.
     */
    private function demoAccounts(): void
    {
        $password = config('atlas.demo_users.password');

        $accounts = [
            [config('atlas.demo_users.engineer'), UserRole::Engineer],
            [config('atlas.demo_users.assistant'), UserRole::Assistant],
            [config('atlas.demo_users.assistant_two'), UserRole::Assistant],
        ];

        foreach ($accounts as [$account, $role]) {
            $user = User::withTrashed()->updateOrCreate(
                ['email' => $account['email']],
                [
                    'name' => $account['name'],
                    'password' => Hash::make($password),
                    'email_verified_at' => now(),
                    'deleted_at' => null,
                ],
            );

            // Privilege is never mass assignable — see the $fillable docblock
            // on App\Models\User. is_demo is what makes these purgeable.
            $user->forceFill([
                'role' => $role,
                'is_active' => true,
                'is_demo' => true,
                // Deliberately false. Being forced to change the password
                // before you can look at a screen defeats a demo account.
                'must_change_password' => false,
            ])->save();

            $this->command?->info("  Demo {$role->value}: {$account['email']}");
        }

        $this->command?->warn('  Demo accounts share one password, from ATLAS_DEMO_PASSWORD.');
    }
}
