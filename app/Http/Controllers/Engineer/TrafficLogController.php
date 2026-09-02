<?php

namespace App\Http\Controllers\Engineer;

use App\Http\Controllers\Controller;
use App\Models\AccessPoint;
use App\Models\Location;
use App\Models\TelemetryHourly;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Traffic and congestion logs (FR-303).
 *
 * Q9 asked whether this meant per-access-point history or campus-wide trends.
 * The answer taken was BOTH, from one screen: an unfiltered view is the campus
 * trend, and filtering to one access point is its history. Two screens reading
 * the same table would have been two things to keep in step.
 *
 * Served from `telemetry_hourly`. Scanning raw readings for a month of trend
 * would be slow enough to notice during the demo.
 *
 * @see docs/02-open-questions.md Q9
 */
class TrafficLogController extends Controller
{
    public function index(Request $request): View
    {
        [$from, $to] = $this->range($request);

        $rows = TelemetryHourly::query()
            ->with('accessPoint.location')
            ->whereBetween('hour_bucket', [$from, $to])
            ->when($request->filled('access_point'), fn ($q) => $q->where('access_point_id', $request->integer('access_point')))
            ->when($request->filled('location'), fn ($q) => $q->whereHas('accessPoint', fn ($q) => $q->where('location_id', $request->integer('location'))))
            ->orderByDesc('hour_bucket')
            ->paginate(50)
            ->withQueryString();

        return view('engineer.traffic', [
            'rows' => $rows,
            'from' => $from,
            'to' => $to,
            'accessPoints' => AccessPoint::orderBy('ap_code')->get()
                ->mapWithKeys(fn ($ap) => [$ap->id => $ap->ap_code])->all(),
            'locations' => Location::orderBy('code')->get()
                ->mapWithKeys(fn ($l) => [$l->id => $l->label()])->all(),
            'trend' => $this->campusTrend($from, $to, $request),
        ]);
    }

    /**
     * Campus-wide totals per hour, for the chart above the table.
     *
     * Grouped in the database rather than in PHP: a month of hourly rows for
     * forty access points is ~29,000 rows, and pulling them all into a
     * collection to sum them is the kind of thing that works fine on seeded
     * demo data and falls over on real volume.
     *
     * @return array<string, list<mixed>>
     */
    private function campusTrend(Carbon $from, Carbon $to, Request $request): array
    {
        $rows = TelemetryHourly::query()
            ->selectRaw('hour_bucket')
            ->selectRaw('SUM(peak_client_count) as clients')
            ->selectRaw('AVG(avg_latency_ms) as latency')
            ->whereBetween('hour_bucket', [$from, $to])
            ->when($request->filled('access_point'), fn ($q) => $q->where('access_point_id', $request->integer('access_point')))
            ->groupBy('hour_bucket')
            ->orderBy('hour_bucket')
            ->get();

        return [
            'labels' => $rows->map(fn ($r) => Carbon::parse($r->hour_bucket)->format('j M H:i'))->all(),
            'clients' => $rows->map(fn ($r) => (int) $r->clients)->all(),
            'latency' => $rows->map(fn ($r) => $r->latency ? round((float) $r->latency, 1) : null)->all(),
        ];
    }

    /**
     * The requested window, defaulting to the last seven days.
     *
     * Clamped to 90 days: the retention window is 90 days of raw and 12 months
     * of aggregates, and an unbounded range on a shared URL is how somebody
     * accidentally asks for everything.
     *
     * @return array{Carbon, Carbon}
     */
    private function range(Request $request): array
    {
        $to = $request->filled('to')
            ? Carbon::parse($request->string('to'))->endOfDay()
            : now();

        $from = $request->filled('from')
            ? Carbon::parse($request->string('from'))->startOfDay()
            : $to->copy()->subDays(7)->startOfDay();

        if ($from->gt($to)) {
            [$from, $to] = [$to->copy()->subDays(7), $to];
        }

        if ($from->lt($to->copy()->subDays(90))) {
            $from = $to->copy()->subDays(90);
        }

        return [$from, $to];
    }
}
