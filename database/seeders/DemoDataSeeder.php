<?php

namespace Database\Seeders;

use App\Enums\ChecklistResult;
use App\Enums\DeviceStatus;
use App\Enums\InspectionOutcome;
use App\Enums\NetworkStatus;
use App\Enums\TicketCategory;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Models\AccessPoint;
use App\Models\ChecklistItem;
use App\Models\ChecklistTemplate;
use App\Models\Inspection;
use App\Models\LabPc;
use App\Models\Location;
use App\Models\TelemetryHourly;
use App\Models\TelemetryReading;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Risk\RiskScoringService;
use App\Services\Telemetry\TelemetryAggregator;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Everything needed to demonstrate the system, and nothing it needs to run.
 *
 * ONLY RUNS when config('atlas.demo_mode') is true. Every row is tagged
 * is_demo (telemetry: source = 'simulated'), so `atlas:purge-demo-data`
 * removes all of it and leaves reference data and real records alone. A row
 * seeded without its flag is indistinguishable from real data.
 *
 * THE ASSET AGES AND FAULT HISTORIES ARE DESIGNED, NOT RANDOM: a few machines
 * that should be replaced, some getting there, most fine. Random factory ages
 * average out to Low and Medium and leave the maintenance ranking with nothing
 * at the top. The scoring is not tuned to them; they are the kind of asset it
 * is meant to find, and `atlas:recalculate-risk` afterwards populates every
 * band. Deterministic, so the data looks the same on every install.
 *
 * @see docs/05-data-strategy.md — Mechanism 2
 * @see docs/decisions/ADR-0003-single-database-demo-flag.md
 * @see docs/10-simulated-and-real-data.md — what this stands in for
 */
class DemoDataSeeder extends Seeder
{
    /**
     * Eighty machines: enough for the ranking to look like a real inventory
     * and for pagination to matter, and it seeds in seconds.
     */
    private const LAB_PCS = 80;

    private const ACCESS_POINTS = 24;

    /** Days of hourly telemetry. Two weeks is what the risk trends read. */
    private const TELEMETRY_DAYS = 14;

    public function run(): void
    {
        $this->demoAccounts();
        $this->labPcs();
        $this->accessPoints();
        $this->telemetry();
        $this->inspections();
        $this->tickets();

        /*
         * SCORE IT HERE. Left to the scheduler's 02:00 run, a fresh install
         * showed every asset unscored and an empty maintenance ranking, the
         * predictive module looking broken to the first person who opened it.
         * The same call as `atlas:recalculate-risk`, which stays the way to
         * rescore after changing a weight.
         */
        $scored = app(RiskScoringService::class)->recalculateAll();

        $this->command?->line("  Risk scored: {$scored['lab_pcs']} lab PCs and {$scored['access_points']} access points.");
        $this->command?->newLine();
        $this->command?->info('  Demo data seeded.');
        $this->command?->newLine();
    }

    /**
     * One sign-in per role, with fixed credentials the README can quote, so
     * the engineer and assistant screens can be opened on a fresh install.
     *
     * updateOrCreate keyed on email, so re-running the seeder resets a
     * forgotten demo password rather than failing on the unique index.
     *
     * BECAUSE IT MATCHES ON EMAIL, changing an ATLAS_DEMO_*_EMAIL on an
     * already-seeded database creates a SECOND account rather than renaming
     * the first, which keeps every ticket, shift and inspection. Rename the
     * existing rows instead.
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

    /**
     * Lab PCs in four deliberate cohorts.
     *
     * The proportions are roughly what a campus inventory looks like: most
     * machines are fine, a few are overdue, and a small number should have
     * been replaced already. The point is that the ranking has something at
     * the top worth acting on.
     *
     * `last_inspected_at` is null on the oldest cohort ON PURPOSE. Never
     * inspected is the strongest version of that risk factor, not an absence
     * of it, and a machine nobody has looked at in years is exactly what a
     * maintenance priority screen exists to surface.
     */
    private function labPcs(): void
    {
        if (LabPc::where('is_demo', true)->count() >= self::LAB_PCS) {
            $this->command?->line('  Lab PCs already seeded.');

            return;
        }

        $locations = Location::pluck('id')->all();

        if ($locations === []) {
            $this->command?->error('  No locations. Run ReferenceSeeder first.');

            return;
        }

        $cohorts = [
            // [count, years old, days since inspection or null for never]
            'past their life' => [5, 6.8, null],
            'ageing' => [12, 4.8, 45],
            'mid-life' => [33, 2.5, 12],
            'nearly new' => [self::LAB_PCS - 50, 0.6, 5],
        ];

        $n = 0;

        foreach ($cohorts as $label => [$count, $years, $inspectedDaysAgo]) {
            for ($i = 0; $i < $count; $i++) {
                $n++;

                LabPc::factory()->demo()->create([
                    'asset_tag' => sprintf('APU-%04d', 1000 + $n),
                    'hostname' => sprintf('apu-pc-%03d', $n),
                    'location_id' => $locations[$n % count($locations)],
                    'purchase_date' => now()->subDays((int) round($years * 365)),
                    'warranty_expiry' => now()->subDays((int) round($years * 365))->addYears(3),
                    'last_inspected_at' => $inspectedDaysAgo === null
                        ? null
                        : now()->subDays($inspectedDaysAgo),
                    'status' => DeviceStatus::Operational->value,
                ]);
            }

            $this->command?->line("  {$count} lab PCs — {$label}");
        }
    }

    /**
     * Access points, three of which are quietly getting worse.
     *
     * An access point is scored on TRENDS rather than levels, so a demo
     * dataset where every link is steady — however bad — produces no risk at
     * all. `telemetry()` gives these three a deteriorating fortnight.
     */
    private function accessPoints(): void
    {
        if (AccessPoint::where('is_demo', true)->count() >= self::ACCESS_POINTS) {
            $this->command?->line('  Access points already seeded.');

            return;
        }

        $locations = Location::pluck('id')->all();

        // Same guard as labPcs(). This seeder must survive being run on its
        // own — several tests do exactly that to check the accounts, and a
        // teammate re-running it to reset a demo password should not need
        // ReferenceSeeder to have gone first.
        if ($locations === []) {
            $this->command?->warn('  Skipping access points: no locations. Run ReferenceSeeder first.');

            return;
        }

        for ($i = 1; $i <= self::ACCESS_POINTS; $i++) {
            AccessPoint::factory()->demo()->create([
                'code' => sprintf('AP-%02d', $i),
                'location_id' => $locations[$i % count($locations)],
                'client_capacity' => [30, 50, 80][$i % 3],
            ]);
        }

        $this->command?->line('  '.self::ACCESS_POINTS.' access points');
    }

    /**
     * Two weeks of telemetry: hourly rows, and the last day as readings.
     *
     * Written directly rather than by running the collector for a fortnight,
     * so a fresh install has charts and risk trends immediately, without a
     * second command. Bulk inserted in chunks: eight thousand model saves is
     * about a minute of somebody's life for no benefit.
     */
    private function telemetry(): void
    {
        $aps = AccessPoint::where('is_demo', true)->orderBy('id')->get();

        if ($aps->isEmpty()) {
            return;
        }

        /*
         * REWRITE THE WINDOW RATHER THAN SKIP IT.
         *
         * An earlier version skipped whenever the database already held any
         * simulated telemetry, which meant `atlas:backfill-telemetry` running
         * first left the three degrading access points with ordinary steady
         * readings instead. Their trend is the whole reason they exist — a
         * link that is getting worse is what M7 scores — and without it the
         * High band was empty.
         *
         * Deleting this window first also avoids the unique index on
         * (access_point_id, hour_bucket), which is what a plain re-run would
         * otherwise collide with.
         */
        $windowStart = now()->subDays(self::TELEMETRY_DAYS)->startOfHour();

        /*
         * THE LAST DAY GOES IN AS READINGS. The Network monitor charts raw
         * readings over the last day, so hourly rows alone opened a fresh
         * install onto an empty chart. The readings come from the same model,
         * and aggregation builds that day's buckets from them, so the
         * scheduler rebuilding those hours keeps the trend rather than
         * flattening it.
         */
        $readingsFrom = now()->subDay()->startOfHour();

        TelemetryHourly::whereIn('access_point_id', $aps->pluck('id'))
            ->where('hour_bucket', '>=', $windowStart)
            ->delete();
        TelemetryReading::whereIn('access_point_id', $aps->pluck('id'))
            ->where('recorded_at', '>=', $readingsFrom)
            ->delete();

        $hours = self::TELEMETRY_DAYS * 24;
        $rows = [];
        $readings = [];
        $lastSeen = [];

        foreach ($aps as $index => $ap) {
            // The first three deteriorate across the window; the rest hold
            // steady with ordinary daily variation.
            $degrading = $index < 3;

            // Through the current hour, so the readings reach the present.
            for ($h = 0; $h <= $hours; $h++) {
                $at = $windowStart->copy()->addHours($h);
                $progress = $h / $hours;

                // A degrading link drops out occasionally, and more often as
                // it gets worse. That is the offline_events factor.
                $dropsOut = $degrading && $progress > 0.5 && $h % 17 === 0;

                if ($at->lt($readingsFrom)) {
                    $busy = $this->busyness($at);
                    [$latency, $loss] = $this->linkQuality($degrading, $progress, $busy, $index);

                    $rows[] = [
                        'access_point_id' => $ap->id,
                        'hour_bucket' => $at->toDateTimeString(),
                        'avg_latency_ms' => round($latency, 2),
                        'peak_client_count' => (int) round($ap->client_capacity * (0.25 + $busy * 0.7)),
                        'avg_packet_loss_pct' => round($loss, 2),
                        'avg_channel_util_pct' => round(20 + $busy * 55, 2),
                        'uptime_pct' => $dropsOut ? 92.5 : 100,
                        'reading_count' => 60,
                        'source' => 'simulated',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];

                    continue;
                }

                $hourEnd = $at->copy()->addHour();

                for ($moment = $at->copy(); $moment->lt($hourEnd) && $moment->lt(now()); $moment->addMinutes(5)) {
                    $down = $dropsOut && $moment->eq($at);
                    $readings[] = $this->reading($ap, $moment, $degrading, $progress, $index, $down);

                    if (! $down) {
                        $lastSeen[$ap->id] = $moment->toDateTimeString();
                    }
                }
            }
        }

        foreach (array_chunk($rows, 1000) as $chunk) {
            DB::table('telemetry_hourly')->insert($chunk);
        }

        foreach (array_chunk($readings, 1000) as $chunk) {
            TelemetryReading::insert($chunk);
        }

        app(TelemetryAggregator::class)->rebuildFrom($readingsFrom);

        /*
         * TELL THE ACCESS POINTS THEY HAVE BEEN HEARD FROM. Otherwise they read
         * "Never polled" beside an Online badge, and the silence sweep, which
         * skips kit that has never answered, ignores them.
         *
         * The three degrading access points stay ONLINE: high latency and
         * occasional downtime are degradation, not absence, and the risk model
         * is what surfaces them.
         */
        foreach ($lastSeen as $id => $at) {
            AccessPoint::whereKey($id)->update(['last_seen_at' => $at]);
        }

        $this->command?->line('  '.number_format(count($rows)).' hourly telemetry rows and '
            .number_format(count($readings)).' readings, '.self::TELEMETRY_DAYS.' days');
    }

    /**
     * Latency and packet loss for one hour, from the model the whole window
     * shares, so the last day's readings continue the hourly rows' trend.
     *
     * @return array{float, float}
     */
    private function linkQuality(bool $degrading, float $progress, float $busy, int $index): array
    {
        return [
            $degrading ? 18 + ($progress * 130) + ($busy * 25) : 16 + ($busy * 22) + (($index % 5) * 3),
            $degrading ? ($progress * 6.5) + ($busy * 0.6) : ($busy * 0.7),
        ];
    }

    /**
     * One five-minute reading for the last seeded day.
     *
     * A small wobble so the ten-minute chart is not a staircase of hourly
     * steps. Throughput follows the simulator's rule, so live readings carry
     * on from these in the same shape.
     *
     * @return array<string, mixed>
     */
    private function reading(AccessPoint $ap, Carbon $at, bool $degrading, float $progress, int $index, bool $down): array
    {
        $row = [
            'access_point_id' => $ap->id,
            'recorded_at' => $at->toDateTimeString(),
            'source' => 'simulated',
        ];

        if ($down) {
            return $row + [
                'latency_ms' => null,
                'client_count' => 0,
                'packet_loss_pct' => 100,
                'channel_util_pct' => 0,
                'throughput_mbps' => 0,
                'status' => NetworkStatus::Offline->value,
            ];
        }

        $busy = $this->busyness($at);
        $wobble = 1 + 0.05 * sin($at->getTimestamp() / 600 + $ap->id);
        [$latency, $loss] = $this->linkQuality($degrading, $progress, $busy, $index);

        $capacity = max(1, (int) $ap->client_capacity);
        $clients = (int) round($capacity * (0.25 + $busy * 0.7) * $wobble);
        $saturation = $clients / $capacity;

        return $row + [
            'latency_ms' => (int) round($latency * $wobble),
            'client_count' => $clients,
            'packet_loss_pct' => round($loss, 2),
            'channel_util_pct' => round(20 + $busy * 55, 2),
            'throughput_mbps' => round($clients * 1.9 * max(0.25, 1 - ($saturation ** 2) * 0.55), 2),
            'status' => NetworkStatus::Online->value,
        ];
    }

    /**
     * Completed inspections, with genuine critical failures on the old kit.
     *
     * `critical_fail_rate` is a quarter of a lab PC's risk score and reads
     * `checklist_items.is_critical`, so an inspection that only fails
     * cosmetic items contributes nothing. The ageing cohort therefore fails
     * items that actually matter.
     */
    private function inspections(): void
    {
        if (Inspection::count() > 20) {
            $this->command?->line('  Inspections already seeded.');

            return;
        }

        $template = ChecklistTemplate::query()->where('is_active', true)->first();
        $assistant = User::query()->active()->role(UserRole::Assistant)->first();

        if (! $template || ! $assistant) {
            $this->command?->warn('  Skipping inspections: no active checklist template or assistant.');

            return;
        }

        $items = ChecklistItem::where('checklist_template_id', $template->id)->get();

        // The machines that have been inspected, worst first.
        $pcs = LabPc::where('is_demo', true)
            ->whereNotNull('last_inspected_at')
            ->orderBy('purchase_date')
            ->take(25)
            ->get();

        foreach ($pcs as $i => $pc) {
            // The oldest third fail; the rest pass. A pass rate of roughly
            // 70% is believable and gives the report's per-location chart
            // something with variation in it.
            $failing = $i < 8;

            $inspection = Inspection::factory()
                ->completed($failing ? InspectionOutcome::Fail : InspectionOutcome::Pass)
                ->create([
                    'lab_pc_id' => $pc->id,
                    'user_id' => $assistant->id,
                    'checklist_template_id' => $template->id,
                    'completed_at' => $pc->last_inspected_at,
                    'started_at' => $pc->last_inspected_at?->copy()->subMinutes(12),
                ]);

            foreach ($items as $item) {
                $failsThis = $failing && $item->is_critical && $item->id % 2 === 0;

                $inspection->results()->create([
                    'checklist_item_id' => $item->id,
                    'result' => $failsThis ? ChecklistResult::Issue : ChecklistResult::Ok,
                    'remark' => $failsThis ? 'Reproduced twice. Needs a hardware swap.' : null,
                ]);
            }
        }

        $this->command?->line('  '.$pcs->count().' completed inspections');
    }

    /**
     * Tickets across every state, weighted onto the machines that deserve them.
     *
     * `fault_frequency` counts tickets in the last 90 days, so putting several
     * against the oldest machines is what makes that factor mean anything.
     * Spread across statuses so the queue, the SLA report and the mean time to
     * resolution all have something to show.
     */
    private function tickets(): void
    {
        if (Ticket::where('is_demo', true)->count() > 10) {
            $this->command?->line('  Tickets already seeded.');

            return;
        }

        $assistant = User::query()->active()->role(UserRole::Assistant)->first();
        $engineer = User::query()->active()->role(UserRole::Engineer)->first();

        if (! $assistant || ! $engineer) {
            $this->command?->warn('  Skipping tickets: no active assistant or engineer.');

            return;
        }

        $worst = LabPc::where('is_demo', true)->orderBy('purchase_date')->take(5)->get();
        $made = 0;

        /*
         * Eight faults each against the five oldest machines, inside the
         * 90-day window the risk model counts.
         *
         * Eight rather than four because four put them at 76 — High, but not
         * Critical, and a demonstration with an empty Critical band does not
         * show what the top of the ranking is for. Eight repairs in three
         * months is also a fair description of a machine that should have
         * been replaced.
         */
        foreach ($worst as $pc) {
            for ($i = 0; $i < 8; $i++) {
                $opened = now()->subDays(random_int(3, 80));

                Ticket::factory()->demo()->create([
                    'title' => 'Repeated fault on '.$pc->asset_tag,
                    'category' => TicketCategory::Hardware,
                    'priority' => TicketPriority::Medium,
                    'status' => TicketStatus::Closed,
                    'ticketable_type' => LabPc::class,
                    'ticketable_id' => $pc->id,
                    'location_id' => $pc->location_id,
                    'reported_by' => $assistant->id,
                    'assigned_to' => $engineer->id,
                    'opened_at' => $opened,
                    'due_at' => $opened->copy()->addHours(24),
                    'acknowledged_at' => $opened->copy()->addHours(2),
                    'resolved_at' => $opened->copy()->addHours(random_int(6, 30)),
                    'closed_at' => $opened->copy()->addHours(random_int(31, 40)),
                    'resolution_notes' => 'Replaced the failing component.',
                ]);

                $made++;
            }
        }

        // A few live ones, so the queue is not empty and the SLA badge has
        // something to colour.
        foreach ([TicketStatus::Open, TicketStatus::Acknowledged, TicketStatus::InProgress] as $status) {
            $opened = now()->subHours(random_int(2, 20));

            Ticket::factory()->demo()->create([
                'title' => 'Wi-Fi dropping in the lab',
                'category' => TicketCategory::Network,
                'priority' => TicketPriority::Critical,
                'status' => $status,

                /*
                 * NULLED EXPLICITLY. TicketFactory defaults `ticketable_id` to
                 * `LabPc::factory()`, so leaving these out silently created
                 * three extra lab PCs — and being made by a factory rather
                 * than by this seeder, they carried no is_demo flag. They
                 * would have survived a purge looking exactly like real
                 * assets. This is the trap the class docblock warns about,
                 * found by counting rows after a seed rather than by any test.
                 *
                 * A dropping Wi-Fi link is a fault about a place anyway, not
                 * about one machine.
                 */
                'ticketable_type' => null,
                'ticketable_id' => null,

                'location_id' => Location::value('id'),
                'reported_by' => $assistant->id,
                'assigned_to' => $status === TicketStatus::Open ? null : $engineer->id,
                'opened_at' => $opened,
                'due_at' => $opened->copy()->addHours(4),
                'acknowledged_at' => $status === TicketStatus::Open ? null : $opened->copy()->addMinutes(40),
            ]);

            $made++;
        }

        $this->command?->line("  {$made} tickets");
    }

    /**
     * How busy the campus is at a given hour, 0 to 1.
     *
     * Quiet overnight, a peak late morning, a dip at lunch, a second peak mid
     * afternoon, and quieter at weekends. Shared shape with
     * SimulatedTelemetrySource so seeded history and collected readings do not
     * look like two different campuses meeting in the middle.
     */
    private function busyness(Carbon $at): float
    {
        $hour = (int) $at->format('G');

        $curve = match (true) {
            $hour < 7, $hour > 21 => 0.05,
            $hour < 9 => 0.35,
            $hour < 12 => 0.9,
            $hour < 14 => 0.6,
            $hour < 17 => 0.85,
            default => 0.4,
        };

        return $at->isWeekend() ? $curve * 0.35 : $curve;
    }
}
