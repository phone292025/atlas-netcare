<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;
use App\Enums\Contracts\HasLabel;
use App\Enums\Contracts\HasTone;

/**
 * The answer to one checklist item during an inspection.
 *
 * Stored on `inspection_results`. An assistant taps one of these per item
 * while standing at the machine, so the set is deliberately tiny.
 *
 * @see docs/03-requirements.md FR-205
 */
enum ChecklistResult: string implements HasLabel, HasTone
{
    use HasValues;

    case Ok = 'ok';
    case Issue = 'issue';
    case Na = 'na';

    public function label(): string
    {
        return match ($this) {
            self::Ok => 'OK',
            self::Issue => 'Issue',
            self::Na => 'N/A',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Ok => 'healthy',
            self::Issue => 'urgent',
            self::Na => 'inactive',
        };
    }

    /** Only a genuine Issue counts against the machine; N/A is neutral. */
    public function isFailure(): bool
    {
        return $this === self::Issue;
    }
}
