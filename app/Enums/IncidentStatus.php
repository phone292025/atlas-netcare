<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;
use App\Enums\Contracts\HasLabel;
use App\Enums\Contracts\HasTone;

/**
 * Where an incident has got to. Open until somebody acknowledges it or it
 * resolves; acknowledging stops the escalation. Only the system resolves an
 * offline or silent incident, when the device or agent is back.
 */
enum IncidentStatus: string implements HasLabel, HasTone
{
    use HasValues;

    case Open = 'open';
    case Acknowledged = 'acknowledged';
    case Resolved = 'resolved';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::Acknowledged => 'Acknowledged',
            self::Resolved => 'Resolved',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Open => 'urgent',
            self::Acknowledged => 'attention',
            self::Resolved => 'healthy',
        };
    }
}
