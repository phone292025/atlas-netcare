<?php

namespace App\Services\Reports;

use App\Enums\InspectionOutcome;
use App\Enums\RiskBand;
use App\Enums\TicketCategory;
use App\Enums\TicketPriority;
use App\Models\AccessPoint;
use App\Models\Inspection;
use App\Models\LabPc;
use App\Models\Location;
use App\Models\TelemetryHourly;
use App\Models\Ticket;
use App\Support\Tone;
use Illuminate\Support\Carbon;

/**
 * The five operational reports (FR-410).
 *
 * Each method returns one figure, shaped for the view and nothing else.
 * Together they are the evidence that the modules connect rather than sitting
 * side by side: inspection pass rates link M3 to outcomes, resolution times
 * show M4's SLA is real, congestion links M5 to places, and the risk spread is
 * M7's headline.
 *
 * WHY THIS IS A SERVICE AND NOT THE CONTROLLER IT USED TO BE. All of it lived
 * in ReportController, which reached 309 lines of query building against a
 * hard rule that says a controller "calls one service method". Every other
 * module in Atlas NetCare has a service; reports was the one place where the
 * aggregation had nowhere to go, so it stayed in the controller and the rule
 * quietly stopped describing the code. Moved 2026-09-04.
 *
 * NO VENDOR-SPECIFIC SQL ANYWHERE IN HERE. The suite runs on SQLite and the
 * system runs on MariaDB, and that gap has already shipped three defects
 * through a fully green suite — MySQL-only FIELD(), MySQL-only DATE_FORMAT,
 * and auto-updating timestamps. Date arithmetic in particular is done in PHP
 * rather than in SQL, because TIMESTAMPDIFF and julianday have nothing in
 * common. The sets involved are hundreds of rows, not millions.
 *
 * @see docs/modules/M7-predictive-and-reports.md
 * @see docs/progress/decision-log.md — 2026-08-29
 */
class ReportingService
{
    /**
     * Assets by risk band — M7's headline analytics figure.
     *
     * Reads the cached columns rather than `risk_assessments`, so it always
     * agrees with the maintenance ranking screen. Both asset types together,
     * because the bands mean the same thing on the same scale.
     *
     * @return array<string, array<string, int>>
     */
    public function riskSpread(): array
    {
        $pcs = LabPc::inService()->selectRaw('risk_band, COUNT(*) as total')
            ->groupBy('risk_band')->pluck('total', 'risk_band');

        $aps = AccessPoint::query()->selectRaw('risk_band, COUNT(*) as total')
            ->groupBy('risk_band')->pluck('total', 'risk_band');

        $rows = [];

        foreach (RiskBand::cases() as $band) {
            $rows[$band->value] = [
                'label' => $band->label(),
                'lab_pcs' => (int) ($pcs[$band->value] ?? 0),
                'access_points' => (int) ($aps[$band->value] ?? 0),
                'total' => (int) ($pcs[$band->value] ?? 0) + (int) ($aps[$band->value] ?? 0),
            ];
        }

        // Assets never scored. Reported rather than hidden, because a report
        // that silently omits rows is how a total stops adding up.
        $rows['unscored'] = [
            'label' => 'Not yet scored',
            'lab_pcs' => LabPc::inService()->whereNull('risk_band')->count(),
            'access_points' => AccessPoint::query()->whereNull('risk_band')->count(),
            'total' => 0,
        ];
        $rows['unscored']['total'] = $rows['unscored']['lab_pcs'] + $rows['unscored']['access_points'];

        return $rows;
    }

    /*
    |---------------------------------------------------------------------
    | Chart and table shapes, added 2026-09-04
    |---------------------------------------------------------------------
    |
    | Chart.js datasets and table headers used to be assembled inside
    | `reports/index.blade.php`, in an `@php` block and four inline
    | `collect()->pluck()` chains. None of it queried anything, so hard rule 2
    | held, but it left the view deciding what the figures were made of — and
    | the one place a `->pluck()` had to skip the "unscored" row was six lines
    | of Blade away from the method that put the row there.
    |
    | These take a figure and return it in the shape the component wants. No
    | queries, no rules, nothing a test cannot read.
    */

    /**
     * `riskSpread()` as a Chart.js bar dataset.
     *
     * The unscored row is deliberately dropped: "not yet scored" is not a risk
     * band, and charting it alongside four that are makes the axis meaningless.
     * It stays in the table underneath, where the total has to add up.
     *
     * @param  array<string, array<string, int|string>>  $spread
     * @return array{labels: list<string>, data: list<int>, colours: list<string>, anyScored: bool}
     */
    public function riskSpreadChart(array $spread): array
    {
        $bands = collect($spread)->except('unscored');

        $data = $bands->pluck('total')->map(fn ($total) => (int) $total)->values()->all();

        return [
            'labels' => $bands->pluck('label')->map(fn ($l) => (string) $l)->values()->all(),
            'data' => $data,
            'colours' => collect(RiskBand::cases())
                ->map(fn (RiskBand $band) => Tone::chart($band->tone()))
                ->values()
                ->all(),

            // Every band at zero means nothing has been scored yet, which is
            // an empty state rather than a chart of four zero-height bars.
            'anyScored' => array_sum($data) > 0,
        ];
    }

    /**
     * `inspectionPassRates()` as a Chart.js bar dataset.
     *
     * @param  list<array<string, mixed>>  $rates
     * @return array{labels: list<string>, data: list<float>, colour: string}
     */
    public function inspectionChart(array $rates): array
    {
        return [
            'labels' => collect($rates)->pluck('location')->map(fn ($l) => (string) $l)->values()->all(),
            'data' => collect($rates)->pluck('rate')->map(fn ($r) => (float) $r)->values()->all(),
            'colour' => Tone::chart('healthy'),
        ];
    }

    /**
     * Column headers for the ticket matrix: Category, one per priority, Total.
     *
     * The priorities are enum cases, and only this knows that the header wants
     * `label()` rather than the case itself.
     *
     * @param  array<string, mixed>  $volume
     * @return list<string|array{label: string, align: string}>
     */
    public function ticketMatrixHeaders(array $volume): array
    {
        return array_merge(
            ['Category'],
            collect($volume['priorities'] ?? [])
                ->map(fn (TicketPriority $priority) => ['label' => $priority->label(), 'align' => 'right'])
                ->values()
                ->all(),
            [['label' => 'Total', 'align' => 'right']],
        );
    }

    /**
     * Ticket volume by category and priority — the descriptive statistics.
     *
     * A matrix rather than two separate counts, because "how many hardware
     * faults" and "how many were critical" are each half an answer.
     *
     * @return array<string, mixed>
     */
    public function ticketVolume(): array
    {
        /*
         * toBase(), so the rows come back as plain objects.
         *
         * Without it Eloquent hydrates Ticket MODELS and casts `category` and
         * `priority` into their enums, so comparing them to a string silently
         * matches nothing and every cell in the matrix reads zero while the
         * heading above it reports the true total. That is exactly what
         * shipped: the screen said "3 tickets" over a grid of noughts, and no
         * test caught it because the tests asserted the headings rather than
         * the numbers.
         */
        $counts = Ticket::query()
            ->selectRaw('category, priority, COUNT(*) as total')
            ->groupBy('category', 'priority')
            ->toBase()
            ->get();

        $matrix = [];

        foreach (TicketCategory::cases() as $category) {
            $row = ['label' => $category->label(), 'total' => 0];

            foreach (TicketPriority::cases() as $priority) {
                $n = (int) ($counts
                    ->firstWhere(fn ($c) => $c->category === $category->value && $c->priority === $priority->value)
                    ?->total ?? 0);

                $row[$priority->value] = $n;
                $row['total'] += $n;
            }

            $matrix[$category->value] = $row;
        }

        return [
            'matrix' => $matrix,
            'priorities' => TicketPriority::cases(),
            'total' => Ticket::count(),
        ];
    }

    /**
     * Mean time to resolution by priority, against the SLA target.
     *
     * SUBTRACTED IN PHP, not in SQL. MySQL wants TIMESTAMPDIFF and SQLite
     * wants julianday, and a query written for one silently returns nonsense
     * on the other rather than failing — which is precisely how the FIELD()
     * and DATE_FORMAT defects reached production.
     *
     * Measured from `opened_at` to `resolved_at`, the same baseline the SLA
     * uses. Unresolved tickets are excluded rather than counted as zero.
     *
     * @return array<string, array<string, mixed>>
     */
    public function resolutionTimes(): array
    {
        $resolved = Ticket::query()
            ->whereNotNull('resolved_at')
            ->get(['priority', 'opened_at', 'resolved_at']);

        $rows = [];

        foreach (TicketPriority::cases() as $priority) {
            $forPriority = $resolved->where('priority', $priority);

            $all = $forPriority
                ->map(fn (Ticket $t) => $t->opened_at->diffInMinutes($t->resolved_at) / 60);

            /*
             * A NEGATIVE DURATION MEANS THE ROW IS CORRUPT, not that the
             * ticket was fixed before it broke. `tickets.opened_at` was a
             * MariaDB `timestamp` until 2026-08-29 and silently rewrote
             * itself to "now" on every status transition, so rows written
             * before that fix can carry an opened_at later than their own
             * resolved_at.
             *
             * Excluded from the mean, because averaging in a negative pulls
             * the figure toward zero and makes the SLA look better than it
             * was. But COUNTED and REPORTED, because the first version of
             * this silently dropped them and the screen then said "nothing
             * resolved yet" about a priority that had a resolved ticket.
             * A report that quietly omits rows is how a total stops adding up.
             */
            $usable = $all->filter(fn (float $h) => $h >= 0);
            $excluded = $all->count() - $usable->count();

            $target = (float) config("atlas.sla_hours.{$priority->value}", 0);
            $mean = $usable->isEmpty() ? null : $usable->avg();

            $rows[$priority->value] = [
                'label' => $priority->label(),
                'resolved' => $all->count(),
                'measurable' => $usable->count(),
                'excluded' => $excluded,
                'mean_hours' => $mean,
                'target_hours' => $target,
                // Whether the AVERAGE met the target. Individual breaches are
                // the alarm's job; this is the aggregate picture.
                'within_target' => $mean !== null && $target > 0 && $mean <= $target,
            ];
        }

        return $rows;
    }

    /**
     * Inspection pass rate by location — links M3 to outcomes.
     *
     * Completed inspections only. An inspection somebody started and
     * abandoned has no outcome, and counting it as a failure would blame a
     * room for an assistant's interrupted shift.
     *
     * @return array<int, array<string, mixed>>
     */
    public function inspectionPassRates(): array
    {
        $rows = Inspection::query()
            ->join('lab_pcs', 'lab_pcs.id', '=', 'inspections.lab_pc_id')
            ->whereNotNull('inspections.completed_at')
            ->selectRaw('lab_pcs.location_id, inspections.overall_result, COUNT(*) as total')
            ->groupBy('lab_pcs.location_id', 'inspections.overall_result')
            ->get();

        $locations = Location::orderBy('code')->get();
        $report = [];

        foreach ($locations as $location) {
            $forLocation = $rows->where('location_id', $location->id);
            $total = (int) $forLocation->sum('total');

            if ($total === 0) {
                continue;
            }

            /*
             * PassWithIssues COUNTS AS A PASS.
             *
             * It is a machine that works with something cosmetic noted — a
             * loose stand, a mark on the screen. Counting it as a failure,
             * which this did until 2026-09-02, made every location's pass rate
             * lower than the truth and put working machines in a column headed
             * "Failed". The enum has three cases and the report only ever
             * looked at one of them.
             */
            /*
             * A closure, not `whereIn`.
             *
             * `overall_result` is cast to the enum, and the two Collection
             * helpers disagree about that: `where()` unwraps a BackedEnum
             * before comparing, `whereIn()` does not — so the obvious
             * translation from one to the other silently matched nothing and
             * reported every location at 0%. It looked correct in review and
             * was caught only because a test asserted the number.
             */
            $passed = (int) $forLocation
                ->filter(fn ($row) => in_array($row->overall_result, [
                    InspectionOutcome::Pass,
                    InspectionOutcome::PassWithIssues,
                ], strict: true))
                ->sum('total');

            $report[] = [
                'location' => $location->label(),
                'total' => $total,
                'passed' => $passed,
                'failed' => $total - $passed,
                'rate' => round($passed / $total * 100, 1),
            ];
        }

        return $report;
    }

    /**
     * Access point uptime and peak congestion by location — links M5 to place.
     *
     * Averaged over the retained aggregate window rather than all history: a
     * room that was bad six months ago and has been fine since should not
     * still read as a problem.
     *
     * @return array<int, array<string, mixed>>
     */
    public function congestionByLocation(): array
    {
        $since = Carbon::now()->subDays(30);

        $rows = TelemetryHourly::query()
            ->join('access_points', 'access_points.id', '=', 'telemetry_hourly.access_point_id')
            ->where('telemetry_hourly.hour_bucket', '>=', $since)
            ->groupBy('access_points.location_id')
            ->selectRaw('access_points.location_id')
            ->selectRaw('AVG(telemetry_hourly.uptime_pct) as uptime')
            ->selectRaw('AVG(telemetry_hourly.avg_latency_ms) as latency')
            ->selectRaw('MAX(telemetry_hourly.peak_client_count) as peak')
            ->selectRaw('COUNT(DISTINCT access_points.id) as aps')
            ->get();

        $names = Location::pluck('code', 'id');

        return $rows->map(fn ($row) => [
            'location' => $names[$row->location_id] ?? 'Unknown',
            'access_points' => (int) $row->aps,
            'uptime' => round((float) $row->uptime, 2),
            'latency' => round((float) $row->latency, 1),
            'peak_clients' => (int) $row->peak,
        ])->sortBy('location')->values()->all();
    }
}
