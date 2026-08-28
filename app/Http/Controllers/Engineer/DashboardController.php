<?php

namespace App\Http\Controllers\Engineer;

use App\Http\Controllers\Controller;
use App\Models\AccessPoint;
use App\Models\TelemetryHourly;
use App\Services\Telemetry\TelemetryService;
use Illuminate\Contracts\View\View;

/**
 * The access point health dashboard (FR-301, FR-304).
 *
 * The headline screen. It renders once from the same data the polling
 * endpoint returns, so the first paint and every refresh after it cannot
 * disagree — see Api\DashboardDataController.
 *
 * @see docs/modules/M5-telemetry-and-dashboard.md
 */
class DashboardController extends Controller
{
    public function __construct(private readonly TelemetryService $telemetry) {}

    public function index(): View
    {
        return view('engineer.dashboard', [
            'data' => $this->telemetry->dashboardData(),
            'pollInterval' => (int) config('atlas.poll_interval'),
        ]);
    }

    /**
     * One access point, with 24 hours of history.
     *
     * The chart reads `telemetry_hourly`, never the raw table — 24 rows
     * instead of 1,440. NFR-02 asks for this under two seconds.
     */
    public function show(AccessPoint $accessPoint): View
    {
        $hours = TelemetryHourly::query()
            ->where('access_point_id', $accessPoint->id)
            ->where('hour_bucket', '>=', now()->subDay()->startOfHour())
            ->orderBy('hour_bucket')
            ->get();

        return view('engineer.access-point', [
            'accessPoint' => $accessPoint->load('location'),
            'hours' => $hours,
            'chart' => [
                'labels' => $hours->map(fn ($h) => $h->hour_bucket->format('H:i'))->all(),
                'clients' => $hours->map(fn ($h) => $h->peak_client_count)->all(),
                'latency' => $hours->map(fn ($h) => $h->avg_latency_ms ? (float) $h->avg_latency_ms : null)->all(),
            ],
            'latest' => $this->telemetry->latestReadings(collect([$accessPoint->id]))->get($accessPoint->id),
        ]);
    }
}
