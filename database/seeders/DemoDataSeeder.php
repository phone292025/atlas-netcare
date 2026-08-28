<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

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
 * INTENTIONALLY EMPTY AT STAGE 4. The tables it will populate arrive with
 * their modules:
 *
 *   M2  lab PCs and access points          ~250 machines, ~40 APs
 *   M3  checklist templates and inspections
 *   M4  tickets across every state
 *   M5  90 days of simulated telemetry
 *   M6  shift templates and rostered shifts
 *
 * Use factories here, not explicit records — the same factories power the
 * feature tests, so there is one set of fixtures rather than two.
 *
 * @see docs/07-data-strategy.md — Mechanism 2
 * @see docs/decisions/ADR-0003-single-database-demo-flag.md
 */
class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        $this->command?->info('  Demo data: nothing to seed yet — arrives with M2 to M6.');
    }
}
