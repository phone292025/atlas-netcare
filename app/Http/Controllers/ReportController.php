<?php

namespace App\Http\Controllers;

use App\Services\Reports\ReportingService;
use Illuminate\Contracts\View\View;

/**
 * Operational reports and descriptive statistics (FR-410).
 *
 * The figures are built by ReportingService; this only renders them.
 *
 * @see app/Services/Reports/ReportingService.php
 * @see docs/modules/M7-predictive-and-reports.md
 */
class ReportController extends Controller
{
    public function index(ReportingService $reports): View
    {
        $riskSpread = $reports->riskSpread();
        $ticketVolume = $reports->ticketVolume();
        $inspections = $reports->inspectionPassRates();

        return view('reports.index', [
            'riskSpread' => $riskSpread,
            'ticketVolume' => $ticketVolume,
            'inspections' => $inspections,
            'resolution' => $reports->resolutionTimes(),
            'congestion' => $reports->congestionByLocation(),

            // Chart and table shapes, so the view renders rather than decides.
            'riskChart' => $reports->riskSpreadChart($riskSpread),
            'inspectionChart' => $reports->inspectionChart($inspections),
            'ticketHeaders' => $reports->ticketMatrixHeaders($ticketVolume),

            'generated' => now(),
        ]);
    }
}
