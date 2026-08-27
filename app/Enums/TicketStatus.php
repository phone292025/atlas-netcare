<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;
use App\Enums\Contracts\HasLabel;
use App\Enums\Contracts\HasTone;

/**
 * Lifecycle states of a workspace incident ticket.
 *
 * PROVISIONAL — open question Q3 is not resolved. These cases come from the
 * proposed lifecycle recorded in docs/04-open-questions.md and may change when
 * the team ratifies it.
 *
 * Deliberately absent until Q3 is Decided:
 *   - the state machine (which transitions are legal)
 *   - transition guards
 *   - SLA breach detection
 *
 * Those belong in TicketService (M4). Do not add them here or anywhere else
 * before Q3 is settled — see docs/progress/decision-log.md.
 */
enum TicketStatus: string implements HasLabel, HasTone
{
    use HasValues;

    case Open = 'open';
    case Acknowledged = 'acknowledged';
    case InProgress = 'in_progress';
    case Resolved = 'resolved';
    case Closed = 'closed';
    case Reopened = 'reopened';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::Acknowledged => 'Acknowledged',
            self::InProgress => 'In Progress',
            self::Resolved => 'Resolved',
            self::Closed => 'Closed',
            self::Reopened => 'Reopened',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Resolved, self::Closed => 'healthy',
            // Every outstanding state is 'attention'. Urgency is the job of the
            // PRIORITY pill, not the status badge — otherwise a brand-new
            // low-priority ticket renders the same red as an offline access
            // point. See docs/08-ui-conventions.md.
            self::Open, self::Acknowledged, self::InProgress, self::Reopened => 'attention',
        };
    }

    /** Whether the ticket still needs somebody to act on it. */
    public function isOutstanding(): bool
    {
        return ! in_array($this, [self::Resolved, self::Closed], strict: true);
    }
}
