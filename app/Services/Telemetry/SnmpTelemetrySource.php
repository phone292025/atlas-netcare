<?php

namespace App\Services\Telemetry;

use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Real access points, over SNMP.
 *
 * A DELIBERATE STUB. ADR-0002 exists because APU IT granting network access to
 * a student project is a permission we do not control and may never receive —
 * so the headline feature was built to not depend on it.
 *
 * This class is what "swapping the driver" means in practice. It is the whole
 * cost of going live: implement collect(), set ATLAS_TELEMETRY_DRIVER=snmp,
 * and nothing else in the system changes. No controller, no view, no
 * migration, no test.
 *
 * It throws rather than returning an empty collection on purpose. A driver
 * that silently produced nothing would look like forty access points that all
 * went quiet at once, and somebody would spend an afternoon on it.
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
