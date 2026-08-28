<?php

namespace App\Models;

use App\Enums\InspectionOutcome;
use Database\Factories\InspectionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * One assistant completing one checklist against one lab PC.
 *
 * `overall_result` is DERIVED by InspectionService from the item results and
 * is absent from $fillable. An assistant records what they saw; the system
 * decides what that adds up to. If a form could set it, a failing machine
 * could be submitted as a pass.
 *
 * @see docs/modules/M3-checklists-and-inspections.md
 * @see App\Services\Maintenance\InspectionService
 */
class Inspection extends Model
{
    /** @use HasFactory<InspectionFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'lab_pc_id',
        'user_id',
        'checklist_template_id',
        'shift_id',
        'notes',
        'started_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'overall_result' => InspectionOutcome::class,
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<LabPc, $this> */
    public function labPc(): BelongsTo
    {
        // withTrashed: an inspection of a machine that has since been retired
        // must still render. Without this the history page shows a row whose
        // machine is null, on exactly the records M2 retires rather than
        // deletes so the history stays readable.
        return $this->belongsTo(LabPc::class)->withTrashed();
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
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

    /**
     * Still being filled in.
     *
     * `completed_at`, not a status column: there are exactly two states and a
     * timestamp already distinguishes them. A third column that could
     * disagree with the timestamp would be one more thing to keep in step.
     */
    public function isInProgress(): bool
    {
        return $this->completed_at === null;
    }

    /** @param  Builder<Inspection>  $query */
    public function scopeInProgress(Builder $query): void
    {
        $query->whereNull('completed_at');
    }

    /** @param  Builder<Inspection>  $query */
    public function scopeCompleted(Builder $query): void
    {
        $query->whereNotNull('completed_at');
    }

    /**
     * The results that found something, for the summary screen and for the
     * pre-filled tickets M4 offers.
     *
     * @return Collection<int, InspectionResult>
     */
    public function issues(): Collection
    {
        return $this->results
            ->filter(fn (InspectionResult $result) => $result->result->isFailure())
            ->values();
    }
}
