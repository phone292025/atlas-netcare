<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;
use App\Enums\Contracts\HasLabel;
use App\Enums\Contracts\HasTone;

/**
 * A condition forced onto an access point by the simulated telemetry driver.
 *
 * Demonstration tooling, not a status. The status is still whatever
 * TelemetryService concludes from the readings the scenario produces — a
 * congested access point turns Degraded only after the configured run of
 * breaching readings, exactly as a real one would. Stored on
 * `access_points.simulation_scenario`; NULL means normal behaviour, which is
 * what "Recovery" sets it back to.
 *
 * Only the simulated driver reads it. Under a live driver the column is
 * ignored and the controls that set it are refused.
 *
 * @see App\Services\Telemetry\SimulationService
 * @see docs/modules/M5-telemetry-and-dashboard.md
 */
enum SimulationScenario: string implements HasLabel, HasTone
{
    use HasValues;

    case Congestion = 'congestion';
    case Outage = 'outage';
    case FeedInterruption = 'feed_interruption';

    public function label(): string
    {
        return match ($this) {
            self::Congestion => 'Congestion',
            self::Outage => 'AP outage',
            self::FeedInterruption => 'Feed interruption',
        };
    }

    /** One line for the scenario picker: what the driver will do. */
    public function description(): string
    {
        return match ($this) {
            self::Congestion => 'Client load over capacity, so latency and loss climb',
            self::Outage => 'The access point stops answering polls',
            self::FeedInterruption => 'No readings arrive. Status becomes unknown, not an inferred outage',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Congestion => 'attention',
            self::Outage => 'urgent',
            self::FeedInterruption => 'neutral',
        };
    }
}
