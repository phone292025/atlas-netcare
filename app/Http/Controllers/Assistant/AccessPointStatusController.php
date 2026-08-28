<?php

namespace App\Http\Controllers\Assistant;

use App\Http\Controllers\Controller;
use App\Services\Telemetry\TelemetryService;
use Illuminate\Contracts\View\View;

/**
 * AP status, read-only, for an assistant on a lab round (FR-209).
 *
 * Same data as the engineer dashboard, no controls. An assistant standing in a
 * lab wants to know whether the Wi-Fi problem a student is reporting is
 * already known — not to change anything.
 *
 * @see docs/modules/M5-telemetry-and-dashboard.md
 */
class AccessPointStatusController extends Controller
{
    public function index(TelemetryService $telemetry): View
    {
        return view('assistant.access-points', [
            'data' => $telemetry->dashboardData(),
            'pollInterval' => (int) config('atlas.poll_interval'),
        ]);
    }
}
