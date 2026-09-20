<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AccessPoint;
use App\Models\Alarm;
use App\Models\ChecklistTemplate;
use App\Models\Inspection;
use App\Models\LabPc;
use App\Models\Location;
use App\Models\Shift;
use App\Models\ShiftTemplate;
use App\Models\Ticket;
use App\Models\User;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Every readable screen, against every role, with data behind it.
 *
 * WHAT THIS ADDS TO WHAT WAS ALREADY HERE. EveryScreenRendersTest loads each
 * screen EMPTY, as the role that owns it. It is the better test of the two for
 * the bug it was written for, and it does not answer this question: what
 * happens when the OTHER two roles ask for the same URL? A screen that
 * 500s for the wrong role, or quietly renders for one that should never see
 * it, passes every test in this project without this table.
 *
 * It also loads the parameterised screens — `show` and `edit` — which the
 * empty sweep cannot reach, because they need a row to point at.
 *
 * HOW TO READ A ROW. Route name, then the status each role should get:
 *
 *     ['admin.users.index', 200, 403, 403]
 *                           ^    ^    ^
 *                       admin  engineer  assistant
 *
 * THREE PATTERNS ARE DELIBERATE AND WORTH KNOWING BEFORE CHANGING A ROW.
 *
 *   `assistant.tickets.*` is 200 for everybody. Raising and reading a fault is
 *   not assistant-only: an engineer who finds one while working on something
 *   else must be able to file it, or faults stop being recorded. The name is
 *   fixed vocabulary, not a claim about who may use it, and TicketPolicy —
 *   not the URL prefix — decides.
 *
 *   `admin.reports.index` is 200 for an engineer. Reports are operational
 *   output, and the engineer is who acts on them.
 *
 *   Every `engineer.*` screen is 200 for an administrator. An administrator
 *   is a superset here, and the sidebar reflects it.
 *
 * @see docs/03-architecture.md — Authorisation
 * @see docs/01-requirements.md FR-104
 */
class RoleAccessMatrixTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{string, int, int, int}>
     */
    public static function screens(): array
    {
        return [
            // Shared
            'profile' => ['profile.edit', 200, 200, 200],
            'notifications' => ['notifications.index', 200, 200, 200],
            'dashboard json' => ['api.dashboard', 200, 200, 200],
            'network monitor json' => ['api.monitor', 200, 200, 200],
            'one access point json' => ['api.monitor.aps.show', 200, 200, 200],

            // The Network monitor: one page, reached through each role's own name
            'network monitor, engineer' => ['engineer.monitor.index', 200, 200, 403],
            'network monitor, admin' => ['admin.monitor.index', 200, 403, 403],
            'network monitor, assistant' => ['assistant.monitor.index', 403, 403, 200],

            // Administrator only
            'users' => ['admin.users.index', 200, 403, 403],
            'new user' => ['admin.users.create', 200, 403, 403],
            'one user' => ['admin.users.show', 200, 403, 403],
            'edit user' => ['admin.users.edit', 200, 403, 403],
            'audit log' => ['admin.audit.index', 200, 403, 403],
            'locations' => ['admin.locations.index', 200, 403, 403],
            'new location' => ['admin.locations.create', 200, 403, 403],
            'edit location' => ['admin.locations.edit', 200, 403, 403],
            'lab pcs' => ['admin.lab-pcs.index', 200, 403, 403],
            'new lab pc' => ['admin.lab-pcs.create', 200, 403, 403],
            'one lab pc' => ['admin.lab-pcs.show', 200, 403, 403],
            'edit lab pc' => ['admin.lab-pcs.edit', 200, 403, 403],
            'ap inventory' => ['admin.aps.index', 200, 403, 403],
            'new ap' => ['admin.aps.create', 200, 403, 403],
            'one ap' => ['admin.aps.show', 200, 403, 403],
            'edit ap' => ['admin.aps.edit', 200, 403, 403],
            'checklists' => ['admin.checklists.index', 200, 403, 403],
            'new checklist' => ['admin.checklists.create', 200, 403, 403],
            'edit checklist' => ['admin.checklists.edit', 200, 403, 403],
            'shift templates' => ['admin.shift-templates.index', 200, 403, 403],
            'new shift template' => ['admin.shift-templates.create', 200, 403, 403],
            'edit shift template' => ['admin.shift-templates.edit', 200, 403, 403],
            'roster' => ['admin.roster.index', 200, 403, 403],

            // Engineer, and the administrator above them
            'engineer dashboard' => ['engineer.dashboard', 200, 200, 403],
            'access points' => ['engineer.aps.index', 200, 200, 403],
            'one access point' => ['engineer.aps.show', 200, 200, 403],
            'traffic logs' => ['engineer.traffic.index', 200, 200, 403],
            'ticket queue' => ['engineer.tickets.index', 200, 200, 403],
            'one queued ticket' => ['engineer.tickets.show', 200, 200, 403],
            'maintenance priority' => ['engineer.risk.index', 200, 200, 403],
            'reports' => ['admin.reports.index', 200, 200, 403],

            // Assistant only
            'inspections' => ['assistant.inspections.index', 403, 403, 200],
            'the checklist form' => ['assistant.inspections.edit', 403, 403, 200],
            'ap status board' => ['assistant.aps.index', 403, 403, 200],
            'my shift' => ['assistant.shifts.today', 403, 403, 200],
            'my roster' => ['assistant.shifts.index', 403, 403, 200],

            // Guarded by TicketPolicy rather than by role — see the docblock
            'my tickets' => ['assistant.tickets.index', 200, 200, 200],
            'raise a ticket' => ['assistant.tickets.create', 200, 200, 200],
            'one of my tickets' => ['assistant.tickets.show', 200, 200, 200],
        ];
    }

    /** @var array<string, int|string> */
    private array $params = [];

    /** @var array<string, User> */
    private array $actors = [];

    private function seedTheWorld(): void
    {
        $this->seed(ReferenceSeeder::class);

        /*
         * The actors are made once, before the fixtures, because the assistant
         * has to OWN the ticket, inspection and shift the parameterised routes
         * point at. An inspection belonging to a different assistant is a 403
         * by design, and that rule is pinned separately below rather than
         * being allowed to make every ownership row in this table read as a
         * failure.
         */
        $this->actors = [
            'admin' => $this->userWith(UserRole::Admin, 'matrix.admin@atlas.local'),
            'engineer' => $this->userWith(UserRole::Engineer, 'matrix.engineer@atlas.local'),
            'assistant' => $this->userWith(UserRole::Assistant, 'matrix.assistant@atlas.local'),
        ];

        $assistant = $this->actors['assistant'];

        $location = Location::first() ?? Location::factory()->create();
        $labPc = LabPc::factory()->create(['location_id' => $location->id]);
        $ap = AccessPoint::factory()->create(['location_id' => $location->id]);
        $template = ChecklistTemplate::first() ?? ChecklistTemplate::factory()->create();
        $shiftTemplate = ShiftTemplate::first() ?? ShiftTemplate::factory()->create();

        $this->params = [
            'access_point' => $ap->id,
            'accessPoint' => $ap->id,
            'lab_pc' => $labPc->id,
            'location' => $location->id,
            'checklist' => $template->id,
            'shift_template' => $shiftTemplate->id,
            'user' => $assistant->id,
            'ticket' => Ticket::factory()->create(['reported_by' => $assistant->id])->id,
            'inspection' => Inspection::factory()->create([
                'lab_pc_id' => $labPc->id,
                'user_id' => $assistant->id,
                'checklist_template_id' => $template->id,
            ])->id,
            'shift' => Shift::factory()->create([
                'user_id' => $assistant->id,
                'location_id' => $location->id,
            ])->id,
            'alarm' => Alarm::factory()->create(['user_id' => $assistant->id])->id,
        ];
    }

    private function userWith(UserRole $role, string $email): User
    {
        $user = User::factory()->create(['email' => $email]);
        $user->forceFill(['role' => $role, 'is_active' => true])->save();

        return $user->fresh();
    }

    private function urlFor(string $name): string
    {
        $route = app('router')->getRoutes()->getByName($name);

        $args = [];

        foreach ($route->parameterNames() as $parameter) {
            $args[$parameter] = $this->params[$parameter];
        }

        return route($name, $args);
    }

    #[DataProvider('screens')]
    public function test_each_role_gets_the_status_it_should(
        string $name,
        int $forAdmin,
        int $forEngineer,
        int $forAssistant,
    ): void {
        $this->seedTheWorld();

        $url = $this->urlFor($name);

        $expected = [
            'admin' => $forAdmin,
            'engineer' => $forEngineer,
            'assistant' => $forAssistant,
        ];

        foreach ($expected as $label => $status) {
            $response = $this->actingAs($this->actors[$label])->get($url);

            $this->assertSame(
                $status,
                $response->getStatusCode(),
                "{$name} gave a {$label} {$response->getStatusCode()}, expected {$status}.",
            );
        }
    }

    /**
     * The rows above give the assistant their OWN records. This is the other
     * half: one assistant must not be able to read another's.
     *
     * Both of these are ownership rules inside a role, so no amount of role
     * middleware expresses them — they live in TicketPolicy and
     * InspectionPolicy, and a route table alone would show both as reachable.
     */
    public function test_one_assistant_cannot_read_another_assistants_records(): void
    {
        $this->seedTheWorld();

        $someoneElse = $this->userWith(UserRole::Assistant, 'matrix.other@atlas.local');

        $this->actingAs($someoneElse)
            ->get($this->urlFor('assistant.tickets.show'))
            ->assertForbidden();

        $this->actingAs($someoneElse)
            ->get($this->urlFor('assistant.inspections.edit'))
            ->assertForbidden();
    }
}
