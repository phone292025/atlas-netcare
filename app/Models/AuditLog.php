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
        'description', 'changes', 'previous', 'ip_address', 'created_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'changes' => 'array',
            'previous' => 'array',
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

    /**
     * One readable line per changed field: "priority: low → critical".
     *
     * Here rather than in the Blade file so the audit screen prints strings
     * and decides nothing.
     *
     * **`getAttribute('changes')`, never `$this->changes`.** Eloquent's
     * HasAttributes trait has its own protected `$changes` property for dirty
     * tracking, so inside this class `$this->changes` reads that array and
     * never reaches the column of the same name. It works from Blade only
     * because a protected property is invisible from outside and `__get()`
     * takes over. A real trap, and the reason this method exists rather than
     * an accessor named after the column.
     *
     * Rows written before 2026-09-04 have no `previous`, so they degrade to
     * "field: newvalue" rather than pretending to know what it had been.
     *
     * @return list<string>
     */
    public function changeSummary(): array
    {
        $changes = $this->getAttribute('changes');

        if (! is_array($changes) || $changes === []) {
            return [];
        }

        $previous = $this->getAttribute('previous');
        $previous = is_array($previous) ? $previous : [];

        return collect($changes)
            ->map(function (mixed $new, string $field) use ($previous): string {
                $label = str($field)->replace('_', ' ')->toString();

                if (! array_key_exists($field, $previous)) {
                    return $label.': '.$this->readable($new);
                }

                return $label.': '.$this->readable($previous[$field]).' → '.$this->readable($new);
            })
            ->values()
            ->all();
    }

    /** A value a person can read, short enough for a table cell. */
    private function readable(mixed $value): string
    {
        return match (true) {
            $value === null, $value === '' => 'empty',
            is_bool($value) => $value ? 'yes' : 'no',
            is_array($value) => '('.count($value).' items)',
            default => str((string) $value)->limit(40)->toString(),
        };
    }
}
