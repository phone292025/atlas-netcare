<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;
use App\Enums\Contracts\HasLabel;
use App\Enums\Contracts\HasTone;

/**
 * Where a rostered shift has got to.
 *
 * Missed is set by `atlas:mark-missed-shifts` within five minutes of a
 * scheduled shift's end with no check-in. It is never chosen by a user.
 *
 * @see docs/modules/M6-shifts-and-alarms.md
 */
enum ShiftStatus: string implements HasLabel, HasTone
{
    use HasValues;

    case Scheduled = 'scheduled';
    case CheckedIn = 'checked_in';
    case Completed = 'completed';
    case Missed = 'missed';

    /**
     * Whether this shift still needs somebody to turn up or finish it.
     *
     * Named to match TicketStatus::isOutstanding(), because it answers the
     * same question about a different thing. A completed or missed shift is
     * history, and the calendar export marks those cancelled so they do not
     * sit in somebody's phone as work still to do.
     */
    public function isOutstanding(): bool
    {
        return in_array($this, [self::Scheduled, self::CheckedIn], strict: true);
    }

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
            // A shift running normally is a good state, not one needing attention.
            self::Completed, self::CheckedIn => 'healthy',
            self::Missed => 'urgent',
            self::Scheduled => 'neutral',
        };
    }
}
