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
    case ApDegraded = 'ap_degraded';
    case ApOffline = 'ap_offline';
    case SlaBreach = 'sla_breach';

    public function label(): string
    {
        return match ($this) {
            self::ShiftStart => 'Shift Starting',
            self::InspectionRound => 'Inspection Round Due',
            self::Handover => 'Handover Required',
            self::ApDegraded => 'Access Point Degraded',
            self::ApOffline => 'Access Point Offline',
            self::SlaBreach => 'Ticket Overdue',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::ShiftStart, self::InspectionRound, self::Handover, self::ApDegraded => 'attention',
            self::ApOffline, self::SlaBreach => 'urgent',
        };
    }
}
