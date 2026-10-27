<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AccessPoint;
use App\Models\Agent;
use App\Models\LabPc;
use App\Models\NetworkDevice;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The list screens must not issue one more query per row.
 *
 * WHY COUNTING QUERIES RATHER THAN READING THE CODE. Eager loading is invisible
 * in the output: a screen missing `->with('location')` renders identically to
 * one that has it, passes every assertion in this suite, and simply gets slower
 * as the inventory grows. Nobody notices on eleven rows. The engineer dashboard
 * lists every access point on campus and the inventory lists every machine, so
 * the row counts here are real ones.
 *
 * HOW IT WORKS. Each screen is loaded twice, with a small set of rows and then
 * a much larger one. A screen that eager-loads its relationships costs the same
 * either way; one that does not costs proportionally more, and the difference
 * is the N+1. The assertion is on the DIFFERENCE, not on an absolute number, so
 * it does not have to be rewritten every time a legitimate query is added.
 *
 * A small allowance is left for pagination counts and anything genuinely keyed
 * to the number of DISTINCT related rows.
 *
 * @see docs/07-testing.md
 */
class ListScreensDoNotNPlusOneTest extends TestCase
{
    use RefreshDatabase;

    private const SMALL = 3;

    private const LARGE = 30;

    /** How many extra queries the larger page may legitimately cost. */
    private const ALLOWANCE = 3;

    /**
     * @return array<string, array{string, string}>
     */
    public static function screens(): array
    {
        return [
            'access point health' => ['engineer.aps.index', 'access_points'],
            'assistant status board' => ['assistant.aps.index', 'access_points'],
            'lab pc inventory' => ['admin.lab-pcs.index', 'lab_pcs'],
            'ap inventory' => ['admin.aps.index', 'access_points'],
            'network devices' => ['admin.devices.index', 'network_devices'],
            'agents' => ['admin.agents.index', 'agents'],
            'ticket queue' => ['engineer.tickets.index', 'tickets'],
            'my tickets' => ['assistant.tickets.index', 'tickets'],
            'maintenance priority' => ['engineer.risk.index', 'lab_pcs'],
        ];
    }

    private function userFor(string $route): User
    {
        $role = match (true) {
            str_starts_with($route, 'admin.') => UserRole::Admin,
            str_starts_with($route, 'engineer.') => UserRole::Engineer,
            default => UserRole::Assistant,
        };

        $user = User::factory()->create();
        $user->forceFill(['role' => $role, 'is_active' => true])->save();

        return $user->fresh();
    }

    private function make(string $subject, int $count, User $reporter): void
    {
        match ($subject) {
            'access_points' => AccessPoint::factory()->count($count)->create(),
            // Each watching a device, so the count beside it has something to count.
            'agents' => Agent::factory()->count($count)->create()->each(
                fn (Agent $agent) => AccessPoint::factory()->create(['agent_id' => $agent->id]),
            ),
            // Each connected to the one before it, so the parent column and the
            // count below it both have something to load.
            'network_devices' => NetworkDevice::factory()->count($count)->create()->each(
                fn (NetworkDevice $device, int $i) => $i > 0 ? $device->update(['parent_id' => $device->id - 1]) : null,
            ),
            'lab_pcs' => LabPc::factory()->count($count)->create(),
            'tickets' => Ticket::factory()->count($count)->create(['reported_by' => $reporter->id]),
        };
    }

    private function queriesToLoad(string $route, User $user): int
    {
        $count = 0;

        DB::flushQueryLog();
        DB::listen(function () use (&$count): void {
            $count++;
        });

        $this->actingAs($user)->get(route($route))->assertOk();

        // Laravel keeps listeners registered for the life of the container, so
        // the counter is rebound rather than removed between measurements.
        return $count;
    }

    #[DataProvider('screens')]
    public function test_the_screen_costs_the_same_whether_it_lists_three_rows_or_thirty(
        string $route,
        string $subject,
    ): void {
        $user = $this->userFor($route);

        $this->make($subject, self::SMALL, $user);
        $small = $this->queriesToLoad($route, $user);

        $this->make($subject, self::LARGE - self::SMALL, $user);
        $large = $this->queriesToLoad($route, $user);

        $growth = $large - $small;

        $this->assertLessThanOrEqual(
            self::ALLOWANCE,
            $growth,
            sprintf(
                '%s cost %d queries for %d rows and %d for %d: %d more for %d extra rows. '.
                'That is a relationship being loaded one row at a time.',
                $route,
                $small,
                self::SMALL,
                $large,
                self::LARGE,
                $growth,
                self::LARGE - self::SMALL,
            ),
        );
    }
}
