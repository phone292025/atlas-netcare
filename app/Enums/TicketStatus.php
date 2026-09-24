<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;
use App\Enums\Contracts\HasLabel;
use App\Enums\Contracts\HasTone;

/**
 * Lifecycle states of a workspace incident ticket.
 *
 * Q3 was settled on 2026-08-28 and these cases are the adopted lifecycle.
 *
 * THE STATE MACHINE IS NOT HERE. Which transitions are legal lives in
 * TicketService::TRANSITIONS, and that is the only place it may live — an enum
 * that knew its own successors would be a second copy of the rule, and the two
 * would disagree within a fortnight.
 *
 * @see App\Services\Tickets\TicketService
 * @see docs/02-open-questions.md Q3
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
            self::InProgress => 'In progress',
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
            // point. See docs/06-ui-conventions.md.
            self::Open, self::Acknowledged, self::InProgress, self::Reopened => 'attention',
        };
    }

    /** Whether the ticket still needs somebody to act on it. */
    public function isOutstanding(): bool
    {
        return ! in_array($this, [self::Resolved, self::Closed], strict: true);
    }
}
