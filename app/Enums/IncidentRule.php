<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;
use App\Enums\Contracts\HasLabel;

/**
 * What opened an incident (ADR-0014, roadmap 2.2).
 *
 *   offline   the device stopped answering. Grouped under a failed parent,
 *             and gets a ticket
 *   degraded  latency, loss or load over its limit for N readings in a row
 *   flapping  the device changed status too often in a short window
 *   silent    an agent stopped reporting, so its devices are unknown
 *
 * @see App\Services\Incidents\IncidentService
 */
enum IncidentRule: string implements HasLabel
{
    use HasValues;

    case Offline = 'offline';
    case Degraded = 'degraded';
    case Flapping = 'flapping';
    case Silent = 'silent';

    public function label(): string
    {
        return match ($this) {
            self::Offline => 'Not responding',
            self::Degraded => 'Degraded',
            self::Flapping => 'Flapping',
            self::Silent => 'Agent silent',
        };
    }

    /** The in-app alarm an alert about it raises. */
    public function alarmType(): AlarmType
    {
        return match ($this) {
            self::Offline => AlarmType::ApOffline,
            self::Degraded => AlarmType::ApDegraded,
            self::Flapping => AlarmType::DeviceFlapping,
            self::Silent => AlarmType::AgentSilent,
        };
    }

    /**
     * Who is told, in order, while nobody acknowledges (roadmap 2.5). The
     * assistant on shift is in the room; an engineer fixes it; an
     * administrator is the last resort. A silent agent is configuration, so
     * it goes straight to engineers and administrators together.
     *
     * @return list<list<UserRole>>
     */
    public function escalation(): array
    {
        return match ($this) {
            self::Silent => [[UserRole::Engineer, UserRole::Admin]],
            default => [[UserRole::Assistant], [UserRole::Engineer], [UserRole::Admin]],
        };
    }

    /** Whether opening one raises a ticket by itself: a device that is down needs work. */
    public function raisesTicket(): bool
    {
        return $this === self::Offline;
    }
}
