<?php

namespace App\Providers;

use App\Models\AccessPoint;
use App\Models\LabPc;
use App\Models\Location;
use App\Models\User;
use App\Observers\AuditObserver;
use App\Services\Telemetry\SimulatedTelemetrySource;
use App\Services\Telemetry\SnmpTelemetrySource;
use App\Services\Telemetry\TelemetrySource;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Models whose every write is recorded in audit_logs.
     *
     * Add a model here and it is audited — there is no second step, and no
     * call site to forget. Modules M2 to M7 append to this list as their
     * tables arrive.
     *
     * @see App\Observers\AuditObserver
     * @see docs/03-requirements.md FR-509, NFR-06
     */
    private const AUDITED = [
        User::class,
        Location::class,
        LabPc::class,
        AccessPoint::class,
    ];

    /**
     * The one place the telemetry driver is chosen.
     *
     * This binding IS the swap ADR-0002 is about: everything downstream —
     * TelemetryService, the collector command, the dashboard, the charts —
     * depends on the interface and cannot tell which implementation it got.
     * Going live is this line resolving differently.
     *
     * An unrecognised driver name throws rather than falling back to the
     * simulator. A typo in .env that silently produced invented data on a
     * system meant to be showing real readings is the worst outcome available
     * here.
     *
     * @see docs/decisions/ADR-0002-telemetry-source-driver.md
     */
    public function register(): void
    {
        $this->app->bind(TelemetrySource::class, function () {
            $driver = config('atlas.telemetry_driver');

            return match ($driver) {
                'simulated' => new SimulatedTelemetrySource,
                'snmp', 'live' => new SnmpTelemetrySource,
                default => throw new InvalidArgumentException(
                    "Unknown telemetry driver [{$driver}]. Set ATLAS_TELEMETRY_DRIVER to 'simulated' or 'snmp'."
                ),
            };
        });
    }

    public function boot(): void
    {
        foreach (self::AUDITED as $model) {
            $model::observe(AuditObserver::class);
        }
    }
}
