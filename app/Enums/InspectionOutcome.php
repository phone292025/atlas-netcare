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
 * NAMED `InspectionOutcome`, NOT `InspectionResult`. The docs originally
 * called this `InspectionResult`, which collides with the Eloquent model for
 * the `inspection_results` table — and almost every file in M3 needs both. PHP
 * refuses two imports with the same short name, so one would have to be
 * aliased in every such file. The distinction now reads correctly anyway: this
 * enum is the outcome of the whole inspection, the model is one row of
 * results.
 *
 * @see docs/modules/M3-checklists-and-inspections.md
 * @see docs/progress/decision-log.md — 2026-08-28
 */
enum InspectionOutcome: string implements HasLabel, HasTone
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

    /**
     * Whether this outcome should put the machine in front of somebody.
     *
     * M7 weighs inspection history; M4 offers a ticket. Both need one place
     * to ask "did this inspection find something?" rather than each writing
     * their own comparison and disagreeing about PassWithIssues.
     */
    public function needsAttention(): bool
    {
        return $this !== self::Pass;
    }
}
