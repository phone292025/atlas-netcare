<?php

namespace App\Services\Telemetry;

use App\Enums\NetworkStatus;
use App\Models\NetworkDevice;
use Illuminate\Support\Collection;

/**
 * What TelemetryService::record() did with a set of readings: which it stored,
 * and which devices changed status as a result.
 *
 * A reading from an agent that was already stored, from a batch sent twice,
 * is in neither list.
 */
final readonly class StoredReadings
{
    /**
     * @param  Collection<int, ReadingData>  $stored
     * @param  Collection<int, array{device: NetworkDevice, from: NetworkStatus, to: NetworkStatus}>  $transitions
     */
    public function __construct(
        public Collection $stored,
        public Collection $transitions,
    ) {}
}
