<?php

namespace App\Http\Controllers\Engineer;

use App\Http\Controllers\Controller;
use App\Models\AccessPoint;
use App\Models\TelemetryHourly;
use App\Models\Ticket;
use App\Services\Telemetry\TelemetryService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * The engineer's overview and the access point health screens (FR-301, FR-304).
 *
 * Both render once from the same data the polling endpoint returns, so the
 * first paint and every refresh after it cannot disagree. See
 * Api\DashboardDataController.
 *
 * @see docs/modules/M5-telemetry-and-dashboard.md
 */
class DashboardController extends Controller
{
    public function __construct(private readonly TelemetryService $telemetry) {}

    /**
     * The engineer's overview: their own work, what is waiting for somebody,
     * and the network's health, on one screen.
     *
     * The access point summary is the same dashboardData() the list polls, so
     * the two screens cannot disagree.
     */
    public function overview(Request $request): View
    {
        $me = $request->user();
        $open = fn () => Ticket::query()->outstanding();

        return view('engineer.dashboard', [
            'data' => $this->telemetry->dashboardData(),
            'pollInterval' => (int) config('atlas.poll_interval'),
            'counts' => [
                'open' => $open()->count(),
                'mine' => $open()->where('assigned_to', $me->id)->count(),
                'unassigned' => $open()->whereNull('assigned_to')->count(),
                'breaching' => Ticket::query()->breaching()->count(),
                'resolvedThisWeek' => Ticket::query()->where('assigned_to', $me->id)
                    ->where('resolved_at', '>=', now()->subDays(7))->count(),
            ],
            'mine' => $open()->where('assigned_to', $me->id)
                ->with(['ticketable', 'location'])->mostUrgentFirst()->limit(6)->get(),
            'waiting' => $open()->whereNull('assigned_to')
                ->with(['ticketable', 'location'])->mostUrgentFirst()->limit(5)->get(),
        ]);
    }

    /** Every monitored access point and its latest reading, kept live. */
    public function index(): View
    {
        return view('engineer.access-points', [
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
                'labels' => $hours->map(fn ($h) => $h->hour_bucket->campus()->format('H:i'))->all(),
                'clients' => $hours->map(fn ($h) => $h->peak_client_count)->all(),
                'latency' => $hours->map(fn ($h) => $h->avg_latency_ms ? (float) $h->avg_latency_ms : null)->all(),
            ],
            'latest' => $this->telemetry->latestReadings(collect([$accessPoint->id]))->get($accessPoint->id),
            // Open work on this device, so nobody raises a second ticket for
            // a fault already being looked at.
            'openTickets' => $accessPoint->tickets()->outstanding()->with('assignee')->mostUrgentFirst()->get(),
        ]);
    }
}
