<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One recorded fact: who changed what, when, from where.
 *
 * Immutable by design. `$timestamps = false` and there is no updated_at column,
 * because editing an audit row defeats the point of having one.
 *
 * @see docs/modules/M1-identity-and-audit.md
 * @see docs/01-requirements.md NFR-06, FR-409, FR-509
 */
class AuditLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id', 'action', 'auditable_type', 'auditable_id',
        'description', 'changes', 'ip_address', 'created_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'changes' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    /** @return MorphTo<Model, $this> */
    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * "Lab PC" rather than "App\Models\LabPc" — the log is read by people.
     */
    public function subjectLabel(): string
    {
        return str(class_basename($this->auditable_type))->headline()->toString();
    }

    /** @param  Builder<AuditLog>  $query */
    public function scopeForSubject(Builder $query, Model $subject): void
    {
        $query->where('auditable_type', $subject->getMorphClass())
            ->where('auditable_id', $subject->getKey());
    }
}
