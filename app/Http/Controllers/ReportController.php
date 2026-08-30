<?php

namespace App\Http\Controllers;

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
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;

/**
 * Operational reports and descriptive statistics (FR-410).
 *
 * Five figures, each one screenshot-ready for the report. They are the
 * evidence that the modules connect to each other rather than sitting side by
 * side: inspection pass rates link M3 to outcomes, resolution times show M4's
 * SLA is real, congestion links M5 to places, and the risk spread is M7's
 * headline.
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
class ReportController extends Controller
{
    public function index(): View
    {
        return view('reports.index', [
            'riskSpread' => $this->riskSpread(),
            'ticketVolume' => $this->ticketVolume(),
            'resolution' => $this->resolutionTimes(),
            'inspections' => $this->inspectionPassRates(),
            'congestion' => $this->congestionByLocation(),
            'generated' => now(),
        ]);
    }

    /**
     * Assets by risk band — M7's headline analytics figure.
     *
     * Reads the cached columns rather than `risk_assessments`, so it always
     * agrees with the maintenance ranking screen. Both asset types together,
     * because the bands mean the same thing on the same scale.
     *
     * @return array<string, array<string, int>>
     */
    private function riskSpread(): array
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

    /**
     * Ticket volume by category and priority — the descriptive statistics.
     *
     * A matrix rather than two separate counts, because "how many hardware
     * faults" and "how many were critical" are each half an answer.
     *
     * @return array<string, mixed>
     */
    private function ticketVolume(): array
    {
        $counts = Ticket::query()
            ->selectRaw('category, priority, COUNT(*) as total')
            ->groupBy('category', 'priority')
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
    private function resolutionTimes(): array
    {
        $resolved = Ticket::query()
            ->whereNotNull('resolved_at')
            ->get(['priority', 'opened_at', 'resolved_at']);

        $rows = [];

        foreach (TicketPriority::cases() as $priority) {
            $forPriority = $resolved->where('priority', $priority);

            $hours = $forPriority
                ->map(fn (Ticket $t) => $t->opened_at->diffInMinutes($t->resolved_at) / 60)
                ->filter(fn (float $h) => $h >= 0);

            $target = (float) config("atlas.sla_hours.{$priority->value}", 0);
            $mean = $hours->isEmpty() ? null : $hours->avg();

            $rows[$priority->value] = [
                'label' => $priority->label(),
                'resolved' => $hours->count(),
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
    private function inspectionPassRates(): array
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

            $passed = (int) $forLocation
                ->where('overall_result', InspectionOutcome::Pass->value)
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
    private function congestionByLocation(): array
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
