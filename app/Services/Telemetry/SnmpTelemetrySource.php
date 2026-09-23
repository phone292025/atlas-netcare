<?php

namespace App\Services\Telemetry;

use App\Models\AccessPoint;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Real access points, over SNMP.
 *
 * A DELIBERATE STUB, because network access to campus access points is a
 * permission this project does not control (ADR-0002). Going live is
 * implementing collect() and setting ATLAS_TELEMETRY_DRIVER=snmp; nothing
 * else in the system changes.
 *
 * It throws rather than returning nothing: an empty result would look like
 * every access point going quiet at once.
 *
 * WHEN IMPLEMENTING THIS:
 *   - credentials belong in .env and config, never in this file
 *   - one ReadingData per access point, and ReadingData::unreachable() for an
 *     AP that does not answer — do not report zeros for a dead AP, or an
 *     outage averages out as excellent latency
 *   - do not classify health here; that is TelemetryService's job
 *   - respect AccessPoint::scopeCollectable(), so an AP under planned
 *     maintenance is not polled and does not raise alarms
 *
 * @see docs/decisions/ADR-0002-telemetry-source-driver.md
 */
class SnmpTelemetrySource implements TelemetrySource
{
    public function sourceLabel(): string
    {
        return 'live';
    }

    /**
     * Every access point not under maintenance, which is what a real SNMP
     * implementation would enumerate. Answerable even though collect()
     * throws: markStaleOffline() asks whichever driver is bound, and a device
     * that fails to enumerate IS going quiet.
     *
     * @return Builder<AccessPoint>
     */
    public function pollable(): Builder
    {
        return AccessPoint::query()->collectable();
    }

    /** @return Collection<int, ReadingData> */
    public function collect(): Collection
    {
        throw new RuntimeException(
            'The SNMP telemetry driver is not implemented. '
            .'Set ATLAS_TELEMETRY_DRIVER=simulated in .env, or implement '
            .self::class.'::collect().'
        );
    }
}
