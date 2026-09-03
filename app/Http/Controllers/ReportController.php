<?php

namespace App\Http\Controllers;

use App\Services\Reports\ReportingService;
use Illuminate\Contracts\View\View;

/**
 * Operational reports and descriptive statistics (FR-410).
 *
 * Five figures, each one screenshot-ready for the report.
 *
 * The aggregation behind them is in ReportingService. It used to be here, and
 * this file was 309 lines of query building against a hard rule that says a
 * controller "calls one service method" — the one place in the project where
 * the rule had stopped describing the code.
 *
 * @see app/Services/Reports/ReportingService.php
 * @see docs/modules/M7-predictive-and-reports.md
 */
class ReportController extends Controller
{
    public function index(ReportingService $reports): View
    {
        return view('reports.index', [
            'riskSpread' => $reports->riskSpread(),
            'ticketVolume' => $reports->ticketVolume(),
            'resolution' => $reports->resolutionTimes(),
            'inspections' => $reports->inspectionPassRates(),
            'congestion' => $reports->congestionByLocation(),
            'generated' => now(),
        ]);
    }
}
