<?php

namespace App\Models;

use App\Enums\ChecklistCategory;
use Database\Factories\ChecklistItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One question on a checklist template.
 *
 * @see docs/modules/M3-checklists-and-inspections.md
 */
class ChecklistItem extends Model
{
    /** @use HasFactory<ChecklistItemFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'checklist_template_id',
        'label',
        'category',
        'is_critical',
        'sort_order',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'category' => ChecklistCategory::class,
            'is_critical' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /** @return BelongsTo<ChecklistTemplate, $this> */
    public function template(): BelongsTo
    {
        return $this->belongsTo(ChecklistTemplate::class, 'checklist_template_id');
    }

    /** @return HasMany<InspectionResult, $this> */
    public function results(): HasMany
    {
        return $this->hasMany(InspectionResult::class);
    }
}
