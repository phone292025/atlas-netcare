<?php

namespace App\Services\Reports;

use App\Enums\DeviceKind;
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
 * Each method returns one figure, shaped for the view. Together they show the
 * modules connecting: inspection pass rates link M3 to outcomes, resolution
 * times show M4's SLA is real, congestion links M5 to places, and the risk
 * spread is M7's headline.
 *
 * No vendor-specific SQL: the suite runs on SQLite and the system on MariaDB,
 * so date arithmetic is done in PHP. The sets are hundreds of rows, not
 * millions.
 *
 * @see docs/modules/M7-predictive-and-reports.md
 */
class ReportingService
{
    /**
     * Assets by risk band, from the cached columns so it always agrees with
     * the maintenance ranking. Both asset types share one scale.
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

        // Reported rather than hidden, so the total adds up.
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
    | Chart and table shapes: a figure in, the shape a component wants out.
    | No queries, so the view decides nothing about what the figures are.
    */

    /**
     * `riskSpread()` as a Chart.js bar dataset.
     *
     * The unscored row is dropped: it is not a band, and charting it beside
     * four that are makes the axis meaningless. The table keeps it.
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

            // All zero means nothing is scored yet: an empty state, not a
            // chart of four zero-height bars.
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
        // Location codes on the axis, not full labels: seven 26-character
        // labels rotate, overlap and eat half the plot. The table beside the
        // chart carries the full name.
        return [
            'labels' => collect($rates)->pluck('code')->map(fn ($c) => (string) $c)->values()->all(),
            'data' => collect($rates)->pluck('rate')->map(fn ($r) => (float) $r)->values()->all(),
            'colour' => Tone::chart('healthy'),
        ];
    }

    /**
     * Column headers for the ticket matrix: Category, one per priority, Total.
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
     * Ticket volume by category and priority. A matrix, because "how many
     * hardware faults" and "how many were critical" are each half an answer.
     *
     * @return array<string, mixed>
     */
    public function ticketVolume(): array
    {
        // toBase(): hydrated models cast category and priority to enums, and
        // comparing an enum with the string below matches nothing, so every
        // cell read zero under a heading reporting the true total.
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
     * From `opened_at` to `resolved_at`, the same baseline the SLA uses, and
     * subtracted in PHP: TIMESTAMPDIFF and julianday have nothing in common.
     * Unresolved tickets are excluded rather than counted as zero.
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
             * A negative duration means a corrupt row, not a fix before the
             * fault: `opened_at` was a MariaDB `timestamp` until 2026-08-29 and
             * rewrote itself on every transition. Such rows are left out of the
             * mean, which they would flatter, but counted and reported, so the
             * totals still add up.
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
                // The AVERAGE against the target. Individual breaches are the
                // alarm's job.
                'within_target' => $mean !== null && $target > 0 && $mean <= $target,
            ];
        }

        return $rows;
    }

    /**
     * Inspection pass rate by location.
     *
     * Completed inspections only: an abandoned one has no outcome, and
     * counting it as a failure would blame a room for an interrupted shift.
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
             * PassWithIssues counts as a pass: the machine works, with
             * something cosmetic noted.
             *
             * A closure rather than whereIn(): `overall_result` is cast to the
             * enum, and Collection::where() unwraps a BackedEnum before
             * comparing while whereIn() does not, so whereIn() matched nothing.
             */
            $passed = (int) $forLocation
                ->filter(fn ($row) => in_array($row->overall_result, [
                    InspectionOutcome::Pass,
                    InspectionOutcome::PassWithIssues,
                ], strict: true))
                ->sum('total');

            $report[] = [
                // Both: the table has room for the label, a chart axis does
                // not. See inspectionChart().
                'location' => $location->label(),
                'code' => $location->code,
                'total' => $total,
                'passed' => $passed,
                'failed' => $total - $passed,
                'rate' => round($passed / $total * 100, 1),
            ];
        }

        return $report;
    }

    /**
     * Access point uptime and peak congestion by location, over the last 30
     * days: a room that was bad six months ago and fine since should not
     * still read as a problem.
     *
     * @return array<int, array<string, mixed>>
     */
    public function congestionByLocation(): array
    {
        $since = Carbon::now()->subDays(30);

        $rows = TelemetryHourly::query()
            ->join('network_devices', 'network_devices.id', '=', 'telemetry_hourly.network_device_id')
            ->where('network_devices.kind', DeviceKind::AccessPoint->value)
            ->where('telemetry_hourly.hour_bucket', '>=', $since)
            ->groupBy('network_devices.location_id')
            ->selectRaw('network_devices.location_id')
            ->selectRaw('AVG(telemetry_hourly.uptime_pct) as uptime')
            ->selectRaw('AVG(telemetry_hourly.avg_latency_ms) as latency')
            ->selectRaw('COUNT(DISTINCT network_devices.id) as aps')
            ->get();

        $peaks = $this->peakClientsByLocation($since);
        $names = Location::pluck('code', 'id');

        return $rows->map(fn ($row) => [
            'location' => $names[$row->location_id] ?? 'Unknown',
            'access_points' => (int) $row->aps,
            'uptime' => round((float) $row->uptime, 2),
            'latency' => round((float) $row->latency, 1),
            'peak_clients' => $peaks[$row->location_id] ?? 0,
        ])->sortBy('location')->values()->all();
    }

    /**
     * The busiest the whole ROOM got, not its busiest single radio.
     *
     * The busiest radio reported the same number for every location, which
     * says nothing about where to add capacity. Summing each access point's
     * hourly peak slightly overstates true concurrent demand, since the peaks
     * need not share a minute; for a capacity decision that is the right
     * direction to err in, and raw readings would mean scanning hundreds of
     * thousands of rows for one figure.
     *
     * Grouped in SQL and reduced in PHP rather than a vendor-specific
     * subquery. One row per location per hour is a few thousand.
     *
     * @return array<int, int> location id => peak concurrent clients
     */
    private function peakClientsByLocation(Carbon $since): array
    {
        $hours = TelemetryHourly::query()
            ->join('network_devices', 'network_devices.id', '=', 'telemetry_hourly.network_device_id')
            ->where('network_devices.kind', DeviceKind::AccessPoint->value)
            ->where('telemetry_hourly.hour_bucket', '>=', $since)
            ->groupBy('network_devices.location_id', 'telemetry_hourly.hour_bucket')
            ->selectRaw('network_devices.location_id')
            ->selectRaw('SUM(telemetry_hourly.peak_client_count) as concurrent')
            ->get();

        $peaks = [];

        foreach ($hours as $hour) {
            $id = (int) $hour->location_id;
            $peaks[$id] = max($peaks[$id] ?? 0, (int) $hour->concurrent);
        }

        return $peaks;
    }
}
