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
     * WHY A DRIVER HAS TO ANSWER THIS. `TelemetryService::markStaleOffline()`
     * marks a device offline when it has gone quiet. Silence is only evidence
     * of failure if somebody was listening: an access point no driver polls is
     * not quiet, it is unasked, and marking it offline raises a ticket about a
     * device nothing ever tried to reach.
     *
     * Both drivers here answer "everything not under maintenance", so today
     * this changes no behaviour. It is the seam that keeps a partial driver
     * honest. Measured on the development database with one that polls a
     * subset: without this method the sweep marked 28 access points offline,
     * raised 28 tickets and rang 56 alarms on the first tick of the scheduler,
     * about hardware nothing had tried to reach.
     *
     * @return Builder<AccessPoint>
     */
    public function pollable(): Builder;

    /** `simulated` or `live` — stamped on every row this driver produces. */
    public function sourceLabel(): string;
}
