<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;
use App\Enums\Contracts\HasLabel;
use App\Enums\Contracts\HasTone;

/**
 * Operational state of a network device: an access point, a switch, a router.
 * Lab PCs have their own, DeviceStatus.
 *
 * Derived from telemetry by TelemetryService, not set by hand — except
 * Maintenance, which an engineer sets to suppress alarms during planned work.
 *
 * Unknown means the agent watching the device has stopped reporting (task
 * 1.6), so nobody can say how the device is. It is not a fault: it raises no
 * ticket, and the next reading replaces it with whatever that reading says.
 *
 * @see docs/02-open-questions.md Q1 for the thresholds
 */
enum NetworkStatus: string implements HasLabel, HasTone
{
    use HasValues;

    case Online = 'online';
    case Degraded = 'degraded';
    case Offline = 'offline';
    case Unknown = 'unknown';
    case Maintenance = 'maintenance';

    public function label(): string
    {
        return match ($this) {
            self::Online => 'Online',
            self::Degraded => 'Degraded',
            self::Offline => 'Offline',
            self::Unknown => 'Unknown',
            self::Maintenance => 'Under maintenance',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Online => 'healthy',
            self::Degraded => 'attention',
            self::Offline => 'urgent',
            self::Unknown, self::Maintenance => 'neutral',
        };
    }
}
