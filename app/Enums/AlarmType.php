<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;
use App\Enums\Contracts\HasLabel;
use App\Enums\Contracts\HasTone;

/**
 * Why an alarm was raised.
 *
 * Alarms are scheduled, not immediate: a row with `trigger_at` in the future
 * is normal, and the dispatcher sends it when due. That is what makes
 * inspection-round reminders possible.
 *
 * In the schema and services this is an "alarm"; in the interface it is
 * presented as a "notification". See docs/glossary.md.
 *
 * @see docs/modules/M6-shifts-and-alarms.md
 */
enum AlarmType: string implements HasLabel, HasTone
{
    use HasValues;

    case ShiftStart = 'shift_start';
    case InspectionRound = 'inspection_round';
    case Handover = 'handover';
    case ShiftRemoved = 'shift_removed';
    case TicketRaised = 'ticket_raised';
    case TicketAssigned = 'ticket_assigned';
    case ApDegraded = 'ap_degraded';
    case ApOffline = 'ap_offline';
    case SlaBreach = 'sla_breach';

    public function label(): string
    {
        return match ($this) {
            self::ShiftStart => 'Shift starting',
            self::InspectionRound => 'Inspection round due',
            self::Handover => 'Handover required',
            self::ShiftRemoved => 'Shift removed',
            self::TicketRaised => 'New ticket',
            self::TicketAssigned => 'Ticket assigned',
            self::ApDegraded => 'Device degraded',
            self::ApOffline => 'Device offline',
            self::SlaBreach => 'Ticket overdue',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::ShiftStart, self::InspectionRound, self::Handover, self::ShiftRemoved,
            self::TicketRaised, self::TicketAssigned, self::ApDegraded => 'attention',
            self::ApOffline, self::SlaBreach => 'urgent',
        };
    }

    /** The glyph beside it in the notification list, from App\Support\Icons. */
    public function icon(): string
    {
        return match ($this) {
            self::ShiftStart => 'calendar-check',
            self::InspectionRound => 'list-checks',
            self::Handover => 'handover',
            self::ShiftRemoved => 'calendar',
            self::TicketRaised => 'ticket',
            self::TicketAssigned => 'user',
            self::ApDegraded => 'warning',
            self::ApOffline => 'wifi-off',
            self::SlaBreach => 'clock',
        };
    }
}
