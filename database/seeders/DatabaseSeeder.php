<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Two seeders, deliberately.
 *
 *   ReferenceSeeder  the minimum a real deployment needs. Always runs.
 *   DemoDataSeeder   the illustration. Only when ATLAS_DEMO_MODE is true.
 *
 * So `php artisan migrate:fresh --seed` gives a full demonstration system on a
 * developer's machine, and the same command with ATLAS_DEMO_MODE=false gives a
 * clean deployable one. No second database, no separate migration path.
 *
 * @see docs/07-data-strategy.md
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(ReferenceSeeder::class);

        if (config('atlas.demo_mode')) {
            $this->call(DemoDataSeeder::class);
        } else {
            $this->command?->info('  ATLAS_DEMO_MODE is off — reference data only.');
        }
    }
}
