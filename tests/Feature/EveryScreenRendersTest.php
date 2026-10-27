<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Every screen, loaded, on a database with nothing in it.
 *
 * M3 shipped three 500 errors onto routes that all had tests, every one
 * asserting a 403 or a redirect — nothing had ever fetched those pages
 * successfully. Empty is also what every screen looks like on the day it
 * ships, and the state most likely to divide by a zero count, average an
 * empty set or chart an empty label array.
 *
 * This is the sweep that makes that class of bug impossible to reintroduce
 * quietly: add a screen, add a row here.
 *
 * @see docs/07-testing.md — the four habits
 */
class EveryScreenRendersTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Route name, and the role that should be able to open it.
     *
     * @return array<string, array{string, string}>
     */
    public static function screens(): array
    {
        return [
            // Assistant
            'my shift' => ['assistant.shifts.today', 'assistant'],
            'inspections' => ['assistant.inspections.index', 'assistant'],
            'my tickets' => ['assistant.tickets.index', 'assistant'],
            'raise a ticket' => ['assistant.tickets.create', 'assistant'],
            'ap status' => ['assistant.aps.index', 'assistant'],
            'network monitor, assistant' => ['assistant.monitor.index', 'assistant'],
            'notifications' => ['notifications.index', 'assistant'],
            'profile' => ['profile.edit', 'assistant'],

            // Engineer
            'engineer dashboard' => ['engineer.dashboard', 'engineer'],
            'network monitor, engineer' => ['engineer.monitor.index', 'engineer'],
            'access points' => ['engineer.aps.index', 'engineer'],
            'traffic logs' => ['engineer.traffic.index', 'engineer'],
            'ticket queue' => ['engineer.tickets.index', 'engineer'],
            'maintenance priority' => ['engineer.risk.index', 'engineer'],

            // Admin
            'users' => ['admin.users.index', 'admin'],
            'network monitor, admin' => ['admin.monitor.index', 'admin'],
            'new user' => ['admin.users.create', 'admin'],
            'locations' => ['admin.locations.index', 'admin'],
            'lab pcs' => ['admin.lab-pcs.index', 'admin'],
            'admin access points' => ['admin.aps.index', 'admin'],
            'network devices' => ['admin.devices.index', 'admin'],
            'new network device' => ['admin.devices.create', 'admin'],
            'agents' => ['admin.agents.index', 'admin'],
            'new agent' => ['admin.agents.create', 'admin'],
            'checklists' => ['admin.checklists.index', 'admin'],
            'shift templates' => ['admin.shift-templates.index', 'admin'],
            'roster' => ['admin.roster.index', 'admin'],
            'audit log' => ['admin.audit.index', 'admin'],
            'reports' => ['admin.reports.index', 'admin'],
        ];
    }

    private function user(string $role): User
    {
        $user = User::factory()->create();
        $user->forceFill(['role' => UserRole::from($role), 'is_active' => true])->save();

        return $user;
    }

    #[DataProvider('screens')]
    public function test_the_screen_renders_with_nothing_in_the_database(string $route, string $role): void
    {
        // Reference data only — locations and one checklist. No assets, no
        // tickets, no inspections, no telemetry, no shifts.
        $this->seed(ReferenceSeeder::class);

        $this->actingAs($this->user($role))
            ->get(route($route))
            ->assertOk();
    }

    #[DataProvider('screens')]
    public function test_the_screen_survives_a_hand_edited_query_string(string $route, string $role): void
    {
        /*
         * `?status[]=x` arrives as an array, and every filter here takes one
         * value. Ten screens answered it with a 500, "Array to string
         * conversion", from the first place that printed or compared it.
         */
        $this->seed(ReferenceSeeder::class);

        $filters = ['search', 'status', 'location', 'role', 'type', 'action', 'priority', 'assignee', 'band',
            'from', 'to', 'access_point', 'sort', 'direction', 'week', 'mine', 'outstanding', 'breaching',
            'unassigned', 'removed', 'retired', 'user', 'page', 'inspection', 'item', 'asset', 'asset_id'];

        $this->actingAs($this->user($role))
            ->get(route($route, array_fill_keys($filters, ['x'])))
            ->assertOk();
    }

    #[DataProvider('screens')]
    public function test_the_screen_renders_with_no_reference_data_either(string $route, string $role): void
    {
        /*
         * Harsher still: not even a location or a checklist template. This is
         * the state between `migrate` and `db:seed`, and somebody will look at
         * the application in it — a division by a zero count or a `first()`
         * that returns null shows up here and nowhere else.
         */
        $this->actingAs($this->user($role))
            ->get(route($route))
            ->assertOk();
    }
}
