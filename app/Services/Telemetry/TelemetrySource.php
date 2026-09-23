<?php

namespace App\Services\Telemetry;

use App\Models\AccessPoint;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Where access point telemetry comes from.
 *
 * THE PROJECT'S ONE GENUINE ABSTRACTION. Everything else in Atlas NetCare is
 * typed in by a user through our own screens and is real the moment it is
 * typed; telemetry is the only data that arrives from outside, and the only
 * data whose availability depends on a permission we do not control.
 *
 * Implementations are selected by `config('atlas.telemetry_driver')`, bound in
 * AppServiceProvider. Swapping simulated for live is an .env change and one
 * class — no controller, view, migration or test moves.
 *
 * A driver's job stops at reporting numbers. It does not classify health, does
 * not write to the database, and does not know what a threshold is.
 *
 * @see docs/decisions/ADR-0002-telemetry-source-driver.md
 * @see docs/05-data-strategy.md
 */
interface TelemetrySource
{
    /**
     * Take one reading from every access point that should be polled.
     *
     * @return Collection<int, ReadingData>
     */
    public function collect(): Collection;

    /**
     * The access points this driver polls, as an unexecuted query.
     *
     * `TelemetryService::markStaleOffline()` only judges these. Silence is
     * evidence of failure only if somebody was listening: marking an unpolled
     * device offline would raise a ticket about hardware nothing tried to
     * reach, which matters as soon as a driver polls a subset.
     *
     * @return Builder<AccessPoint>
     */
    public function pollable(): Builder;

    /** `simulated` or `live` — stamped on every row this driver produces. */
    public function sourceLabel(): string;
}
