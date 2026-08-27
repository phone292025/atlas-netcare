<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/**
 * Operational state of a Wi-Fi access point.
 *
 * Derived from telemetry by TelemetryService, not set by hand — except
 * Maintenance, which an engineer sets to suppress alarms during planned work.
 *
 * @see docs/04-open-questions.md Q1 for the thresholds
 */
enum ApStatus: string
{
    use HasValues;

    case Online = 'online';
    case Degraded = 'degraded';
    case Offline = 'offline';
    case Maintenance = 'maintenance';

    public function label(): string
    {
        return match ($this) {
            self::Online => 'Online',
            self::Degraded => 'Degraded',
            self::Offline => 'Offline',
            self::Maintenance => 'Under Maintenance',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Online => 'healthy',
            self::Degraded => 'attention',
            self::Offline => 'urgent',
            self::Maintenance => 'inactive',
        };
    }

    /**
     * Whether this state should raise an alarm or auto-ticket.
     *
     * Planned maintenance must not page anybody.
     */
    public function isAlarmable(): bool
    {
        return $this === self::Offline || $this === self::Degraded;
    }
}
