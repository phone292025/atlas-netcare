<?php

namespace Database\Seeders;

use App\Enums\ChecklistResult;
use App\Enums\DeviceKind;
use App\Enums\InspectionOutcome;
use App\Enums\TicketCategory;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Models\AccessPoint;
use App\Models\Agent;
use App\Models\ChecklistTemplate;
use App\Models\LabPc;
use App\Models\Location;
use App\Models\NetworkDevice;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * A campus-sized estate for measuring response times (NFR-02).
 *
 * The demo data is sized to be read by a person: 80 machines, 24 access
 * points, a few dozen tickets. This brings a throwaway stack up to what a
 * campus runs: 250 lab PCs, 150 access points, 3,000 tickets of every status
 * and 4,000 finished inspections over 90 days. Telemetry comes afterwards
 * from `atlas:backfill-telemetry`, which covers every simulated device.
 *
 * Every row is tagged is_demo, so `atlas:purge-demo-data` takes it away.
 * NEVER for a real database: run it on a stack made for the measurement.
 * Needs DemoDataSeeder first, for the accounts and the checklist.
 *
 *   php artisan db:seed --class=LoadTestSeeder --force
 *
 * @see docs/07-testing.md, "Response times"
 */
class LoadTestSeeder extends Seeder
{
    private const LAB_PCS = 250;

    private const ACCESS_POINTS = 150;

    private const TICKETS = 3000;

    private const INSPECTIONS = 4000;

    private const HISTORY_DAYS = 90;

    public function run(): void
    {
        $assistants = User::query()->role(UserRole::Assistant)->pluck('id');
        $engineers = User::query()->role(UserRole::Engineer)->pluck('id');
        $template = ChecklistTemplate::current()?->load('items');
        $locations = Location::pluck('id')->all();

        if ($assistants->isEmpty() || $engineers->isEmpty() || ! $template || $locations === []) {
            $this->command?->error('  Run the demo seeder first: no assistants, engineers, checklist or locations.');

            return;
        }

        $this->labPcs($locations);
        $this->accessPoints($locations);
        $this->tickets($assistants->all(), $engineers->all());
        $this->inspections($template, $assistants->all());
    }

    /** @param  list<int>  $locations */
    private function labPcs(array $locations): void
    {
        for ($n = LabPc::count() + 1; $n <= self::LAB_PCS; $n++) {
            LabPc::factory()->demo()->create([
                'asset_tag' => sprintf('APU-%04d', 3000 + $n),
                'hostname' => sprintf('load-pc-%03d', $n),
                'location_id' => $locations[$n % count($locations)],
                'purchase_date' => now()->subDays(random_int(60, 2400)),
                'last_inspected_at' => now()->subDays(random_int(0, 60)),
            ]);
        }

        $this->command?->line('  '.LabPc::count().' lab PCs');
    }

    /** @param  list<int>  $locations */
    private function accessPoints(array $locations): void
    {
        $switches = NetworkDevice::query()->where('kind', DeviceKind::Switch->value)->pluck('id', 'location_id');
        $simulator = Agent::simulator()->id;

        for ($n = AccessPoint::count() + 1; $n <= self::ACCESS_POINTS; $n++) {
            $location = $locations[$n % count($locations)];

            AccessPoint::factory()->demo()->create([
                'code' => sprintf('AP-L%03d', $n),
                'location_id' => $location,
                'parent_id' => $switches->get($location),
                'agent_id' => $simulator,
                'client_capacity' => [30, 50, 80][$n % 3],
            ]);
        }

        $this->command?->line('  '.AccessPoint::count().' access points');
    }

    /**
     * @param  list<int>  $assistants
     * @param  list<int>  $engineers
     */
    private function tickets(array $assistants, array $engineers): void
    {
        $machines = LabPc::pluck('location_id', 'id');
        $morph = (new LabPc)->getMorphClass();
        $sequence = Ticket::highestSequenceThisYear();
        $statuses = TicketStatus::cases();
        $priorities = TicketPriority::cases();
        $categories = TicketCategory::values();
        $rows = [];

        for ($i = Ticket::count(); $i < self::TICKETS; $i++) {
            $opened = now()->subMinutes(random_int(30, self::HISTORY_DAYS * 24 * 60));
            $status = $statuses[array_rand($statuses)];
            $priority = $priorities[array_rand($priorities)];
            $machine = $machines->keys()->random();
            $worked = $status !== TicketStatus::Open;
            $finished = in_array($status, [TicketStatus::Resolved, TicketStatus::Closed], true);

            $rows[] = [
                'ticket_number' => Ticket::formatNumber(++$sequence),
                'title' => 'Load test fault '.($i + 1),
                'description' => 'Seeded for the response-time measurement.',
                'category' => $categories[array_rand($categories)],
                'priority' => $priority->value,
                'status' => $status->value,
                'ticketable_type' => $morph,
                'ticketable_id' => $machine,
                'location_id' => $machines[$machine],
                'reported_by' => $assistants[array_rand($assistants)],
                'assigned_to' => $worked ? $engineers[array_rand($engineers)] : null,
                'opened_at' => $opened,
                'due_at' => $opened->copy()->addHours($priority->slaHours()),
                'acknowledged_at' => $worked ? $opened->copy()->addMinutes(20) : null,
                'resolved_at' => $finished ? $opened->copy()->addHours(random_int(1, 48))->min(now()) : null,
                'closed_at' => $status === TicketStatus::Closed ? now() : null,
                'is_demo' => true,
                'created_at' => $opened,
                'updated_at' => $opened,
            ];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('tickets')->insert($chunk);
        }

        $this->command?->line('  '.Ticket::count().' tickets');
    }

    /** @param  list<int>  $assistants */
    private function inspections(ChecklistTemplate $template, array $assistants): void
    {
        $machines = LabPc::pluck('id')->all();
        $items = $template->items;
        $outcomes = [InspectionOutcome::Pass, InspectionOutcome::Pass, InspectionOutcome::Pass, InspectionOutcome::PassWithIssues, InspectionOutcome::Fail];

        $made = DB::table('inspections')->count();

        while ($made < self::INSPECTIONS) {
            $batch = min(250, self::INSPECTIONS - $made);
            $rows = [];

            for ($i = 0; $i < $batch; $i++) {
                $completed = Carbon::now()->subMinutes(random_int(60, self::HISTORY_DAYS * 24 * 60));

                $rows[] = [
                    'lab_pc_id' => $machines[array_rand($machines)],
                    'user_id' => $assistants[array_rand($assistants)],
                    'checklist_template_id' => $template->id,
                    'overall_result' => $outcomes[array_rand($outcomes)]->value,
                    'started_at' => $completed->copy()->subMinutes(12),
                    'completed_at' => $completed,
                    'created_at' => $completed,
                    'updated_at' => $completed,
                ];
            }

            DB::table('inspections')->insert($rows);
            $ids = DB::table('inspections')->orderByDesc('id')->limit($batch)->pluck('id');

            $results = [];

            foreach ($ids as $id) {
                foreach ($items as $item) {
                    $results[] = ['inspection_id' => $id, 'checklist_item_id' => $item->id, 'result' => ChecklistResult::Ok->value, 'created_at' => now(), 'updated_at' => now()];
                }
            }

            foreach (array_chunk($results, 1000) as $chunk) {
                DB::table('inspection_results')->insert($chunk);
            }

            $made += $batch;
        }

        $this->command?->line("  {$made} inspections");
    }
}
