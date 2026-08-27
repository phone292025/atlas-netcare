<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;

/**
 * Where a rostered shift has got to.
 *
 * Missed is set by the roster command when a scheduled shift's end time passes
 * with no check-in — it is never chosen by a user.
 *
 * @see docs/modules/M6-shifts-and-alarms.md
 */
enum ShiftStatus: string
{
    use HasValues;

    case Scheduled = 'scheduled';
    case CheckedIn = 'checked_in';
    case Completed = 'completed';
    case Missed = 'missed';

    public function label(): string
    {
        return match ($this) {
            self::Scheduled => 'Scheduled',
            self::CheckedIn => 'Checked In',
            self::Completed => 'Completed',
            self::Missed => 'Missed',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Completed => 'healthy',
            self::CheckedIn => 'attention',
            self::Missed => 'urgent',
            self::Scheduled => 'inactive',
        };
    }
}
