<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\Location;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * The minimum a real deployment needs on day one.
 *
 * ALWAYS RUNS — including when ATLAS_DEMO_MODE is false. Nothing here is
 * tagged is_demo, so `atlas:purge-demo-data` leaves all of it alone. That is
 * the whole point of splitting the seeders: this is the system, DemoDataSeeder
 * is the illustration.
 *
 * Records here are written out explicitly rather than through factories.
 * A location called "Level 6 IT Lab 01" should be readable in this file by
 * anyone checking what the system ships with — a factory would hide it behind
 * randomness.
 *
 * @see docs/07-data-strategy.md — Mechanism 2
 * @see docs/decisions/ADR-0003-single-database-demo-flag.md
 */
class ReferenceSeeder extends Seeder
{
    public function run(): void
    {
        $this->firstAdministrator();
        $this->campusLocations();

        // Checklist templates (M3) and shift templates (M6) belong here too,
        // but their tables do not exist yet. Add those sections when the
        // migrations land — this seeder is where they go, not a new one.
    }

    /**
     * Solves the chicken-and-egg problem every system without self-signup has:
     * administrators create all accounts (FR-105), so somebody has to create
     * the first administrator.
     *
     * updateOrCreate keyed on email, so re-running the seeder on an existing
     * database is safe and does not fail on the unique index.
     */
    private function firstAdministrator(): void
    {
        $email = config('atlas.first_admin.email');

        $admin = User::withTrashed()->updateOrCreate(
            ['email' => $email],
            [
                'name' => config('atlas.first_admin.name'),
                'password' => Hash::make(config('atlas.first_admin.password')),
                'email_verified_at' => now(),
                'deleted_at' => null,
            ],
        );

        // Not mass assignable on purpose — privilege is never set from input.
        // See the $fillable docblock on App\Models\User.
        $admin->forceFill([
            'role' => UserRole::Admin,
            'is_active' => true,
            'is_demo' => false,
            'must_change_password' => true,
        ])->save();

        $this->command?->warn("  Administrator: {$email}");
        $this->command?->warn('  Change this password immediately after signing in.');
    }

    /**
     * The three zones named in the approved proposal's scope: the Library,
     * the IT Labs and the Campus Cafeteria.
     *
     * Keyed on `code`, so running this twice does not duplicate a lab.
     */
    private function campusLocations(): void
    {
        $locations = [
            ['code' => 'L6-IT-01', 'name' => 'Level 6 IT Lab 01', 'type' => 'it_lab', 'floor' => 'Level 6'],
            ['code' => 'L6-IT-02', 'name' => 'Level 6 IT Lab 02', 'type' => 'it_lab', 'floor' => 'Level 6'],
            ['code' => 'L5-NET-01', 'name' => 'Level 5 Networking Lab', 'type' => 'it_lab', 'floor' => 'Level 5'],
            ['code' => 'LIB-L3', 'name' => 'Library, Level 3', 'type' => 'library', 'floor' => 'Level 3'],
            ['code' => 'LIB-L4', 'name' => 'Library, Level 4 Quiet Study', 'type' => 'library', 'floor' => 'Level 4'],
            ['code' => 'CAF-GF', 'name' => 'Campus Cafeteria', 'type' => 'cafeteria', 'floor' => 'Ground Floor'],
        ];

        foreach ($locations as $location) {
            Location::updateOrCreate(['code' => $location['code']], $location);
        }

        $this->command?->info('  '.count($locations).' campus locations.');
    }
}
