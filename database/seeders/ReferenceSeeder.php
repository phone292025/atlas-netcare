<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\ChecklistTemplate;
use App\Models\Location;
use App\Models\User;
use App\Services\Maintenance\ChecklistService;
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
 * @see docs/05-data-strategy.md — Mechanism 2
 * @see docs/decisions/ADR-0003-single-database-demo-flag.md
 */
class ReferenceSeeder extends Seeder
{
    /**
     * The name is a constant because it is an identity, not a label: the
     * seeder keys on it to stay idempotent, and versioning an edited template
     * carries it forward. Two spellings would produce two template families.
     */
    private const CHECKLIST_NAME = 'Standard Lab PC Check';

    public function run(): void
    {
        $this->firstAdministrator();
        $this->campusLocations();
        $this->standardChecklist();

        // Shift templates (M6) belong here too, but their table does not
        // exist yet. Add that section when the migration lands — this seeder
        // is where it goes, not a new one.
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
        /*
         * Refuse rather than fall back to a published default.
         *
         * config('atlas.first_admin.password') used to default to a value
         * printed in .env.example. Seeding without ATLAS_ADMIN_PASSWORD set
         * therefore created the highest privilege account in the system with a
         * credential anybody who has seen the repository already knows.
         */
        if (blank(config('atlas.first_admin.password'))) {
            $this->command?->error('  ATLAS_ADMIN_PASSWORD is not set. The administrator was NOT created.');
            $this->command?->line('  Set it in .env, then run this seeder again.');

            return;
        }

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

    /**
     * The checklist an assistant fills in at a machine.
     *
     * Reference data, not demo data: a deployment with no active template
     * cannot start an inspection at all, so the system ships with one. It is
     * written out in full here so anyone can read what the system asks
     * without opening the admin screen.
     *
     * The four critical items are the ones that mean the machine cannot be
     * used at all — a student sitting down at it would have to get up again.
     * Everything else is a fault worth recording that still leaves a usable
     * machine, which is the whole difference between `fail` and
     * `pass_with_issues`.
     *
     * Seeded INACTIVE-then-activated through the same code path the admin
     * screen uses, so there is exactly one place that can ever produce two
     * active templates, and it is tested.
     */
    private function standardChecklist(): void
    {
        // Keyed on name + version: re-running the seeder must not create a
        // second copy, and must not disturb a template an administrator has
        // since versioned.
        $existing = ChecklistTemplate::query()
            ->where('name', self::CHECKLIST_NAME)
            ->exists();

        if ($existing) {
            $this->command?->info('  Checklist template already present.');

            return;
        }

        $template = ChecklistTemplate::create([
            'name' => self::CHECKLIST_NAME,
            'description' => 'Routine check carried out on each lab workstation during a round.',
        ]);

        $items = [
            ['Machine powers on and reaches the desktop', 'boot', true],
            ['No unexpected error messages at start-up', 'boot', false],
            ['Fans are not running loudly or continuously', 'boot', false],
            ['Monitor displays a stable picture', 'display', true],
            ['No flicker, lines or dead pixels', 'display', false],
            ['Correct resolution and no stretching', 'display', false],
            ['Keyboard responds, all keys working', 'peripheral', true],
            ['Mouse responds and tracks correctly', 'peripheral', true],
            ['Headphone socket produces sound', 'peripheral', false],
            ['USB ports accept a device', 'peripheral', false],
            ['Wired network connects', 'network', true],
            ['Campus Wi-Fi is reachable', 'network', false],
            ['Browser reaches an external site', 'network', false],
        ];

        foreach ($items as $index => [$label, $category, $isCritical]) {
            $template->items()->create([
                'label' => $label,
                'category' => $category,
                'is_critical' => $isCritical,
                'sort_order' => $index + 1,
            ]);
        }

        app(ChecklistService::class)->activate($template);

        $this->command?->info('  Checklist template with '.count($items).' items.');
    }
}
