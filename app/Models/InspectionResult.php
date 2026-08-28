<?php

namespace App\Models;

use App\Enums\ChecklistResult;
use Database\Factories\InspectionResultFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One answer to one checklist item within one inspection.
 *
 * Note the two names in play: `$result->result` is a ChecklistResult
 * (ok/issue/na), while the inspection's `overall_result` is an
 * InspectionOutcome (pass/pass_with_issues/fail). The enum was renamed from
 * InspectionResult precisely so this file can import both without aliasing.
 *
 * @see docs/modules/M3-checklists-and-inspections.md
 */
class InspectionResult extends Model
{
    /** @use HasFactory<InspectionResultFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'inspection_id',
        'checklist_item_id',
        'result',
        'remark',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'result' => ChecklistResult::class,
        ];
    }

    /** @return BelongsTo<Inspection, $this> */
    public function inspection(): BelongsTo
    {
        return $this->belongsTo(Inspection::class);
    }

    /** @return BelongsTo<ChecklistItem, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(ChecklistItem::class, 'checklist_item_id');
    }

    /**
     * Whether this answer is the kind that fails the whole inspection.
     *
     * An Issue on a critical item is a `fail`; the same Issue on a
     * non-critical item is only `pass_with_issues`. Both InspectionService and
     * the summary screen need this distinction, so it lives here rather than
     * being written twice.
     */
    public function isCriticalFailure(): bool
    {
        return $this->result->isFailure() && (bool) $this->item?->is_critical;
    }
}
