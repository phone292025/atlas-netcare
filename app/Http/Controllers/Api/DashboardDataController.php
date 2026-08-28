<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Telemetry\TelemetryService;
use Illuminate\Http\JsonResponse;

/**
 * The endpoint the dashboard polls (FR-302).
 *
 * Polling, not WebSockets — ADR-0005. Telemetry is collected once a minute,
 * so there is nothing a socket would deliver sooner, and a socket server is
 * infrastructure seven students would have to run and explain.
 *
 * It returns exactly what DashboardController rendered from, so a poll can
 * never show different numbers from the first paint.
 *
 * @see docs/decisions/ADR-0005-ajax-polling-not-websockets.md
 */
class DashboardDataController extends Controller
{
    public function __invoke(TelemetryService $telemetry): JsonResponse
    {
        // Behind `auth` in the route, so this is not an open endpoint — the
        // access point layout of a campus network is not public information.
        return response()->json($telemetry->dashboardData());
    }
}
