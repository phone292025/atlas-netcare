<?php

namespace App\Enums;

use App\Enums\Concerns\HasValues;
use App\Enums\Contracts\HasLabel;
use App\Enums\Contracts\HasTone;

/**
 * The overall outcome of one inspection.
 *
 * DERIVED, never entered by hand. InspectionService computes it from the
 * individual item results:
 *
 *   any critical item marked Issue  -> Fail
 *   any non-critical item as Issue  -> PassWithIssues
 *   otherwise                       -> Pass
 *
 * @see docs/modules/M3-checklists-and-inspections.md
 */
enum InspectionResult: string implements HasLabel, HasTone
{
    use HasValues;

    case Pass = 'pass';
    case PassWithIssues = 'pass_with_issues';
    case Fail = 'fail';

    public function label(): string
    {
        return match ($this) {
            self::Pass => 'Pass',
            self::PassWithIssues => 'Pass with Issues',
            self::Fail => 'Fail',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Pass => 'healthy',
            self::PassWithIssues => 'attention',
            self::Fail => 'urgent',
        };
    }
}
