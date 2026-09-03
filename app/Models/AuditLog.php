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
     * Foreign-key columns worth showing as a name instead of a number.
     *
     * `assigned to: empty → 5` is accurate and useless: nobody reading an
     * audit log knows who 5 is. Each entry is the column, the model it points
     * at, and the attribute to show.
     *
     * Deliberately a short explicit list rather than anything that guesses
     * from a `_id` suffix. `tickets.ticketable_id` is polymorphic and has no
     * single model to resolve against, and `inspection_id` has no short label
     * a person would recognise — both are better left as numbers than
     * resolved wrongly.
     *
     * @var array<string, array{class-string<Model>, string}>
     */
    private const LABELLED = [
        'assigned_to' => [User::class, 'name'],
        'reported_by' => [User::class, 'name'],
        'location_id' => [Location::class, 'code'],
    ];

    /**
     * Names for every foreign key across a page of audit rows, in one query
     * per model rather than one per row.
     *
     * Resolved at READ time on purpose. Writing the name into the log instead
     * would freeze it, and `changes` would stop being a record of what was
     * actually written to the column — the audit log's whole job. The cost is
     * that a renamed person shows under their current name, which for a log
     * read by administrators is the more useful of the two.
     *
     * Soft-deleted users are included: an account being deactivated is
     * precisely when its history matters.
     *
     * @param  iterable<AuditLog>  $logs
     * @return array<string, array<int, string>>
     */
    public static function labelsFor(iterable $logs): array
    {
        $wanted = [];

        foreach ($logs as $log) {
            foreach ([$log->getAttribute('changes'), $log->getAttribute('previous')] as $side) {
                foreach (is_array($side) ? $side : [] as $field => $value) {
                    if (isset(self::LABELLED[$field]) && is_numeric($value)) {
                        $wanted[$field][] = (int) $value;
                    }
                }
            }
        }

        $labels = [];

        foreach ($wanted as $field => $ids) {
            [$model, $attribute] = self::LABELLED[$field];

            $query = $model::query();

            if (method_exists($model, 'bootSoftDeletes')) {
                $query->withTrashed();
            }

            $labels[$field] = $query->whereIn('id', array_unique($ids))
                ->pluck($attribute, 'id')
                ->all();
        }

        return $labels;
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
     * $labels comes from labelsFor(), and turns "assigned to: empty → 5" into
     * "assigned to: empty → Phone Myat Ko". Optional: without it the numbers
     * still render, so nothing that forgets to pass it breaks.
     *
     * @return list<string>
     */
    public function changeSummary(array $labels = []): array
    {
        $changes = $this->getAttribute('changes');

        if (! is_array($changes) || $changes === []) {
            return [];
        }

        $previous = $this->getAttribute('previous');
        $previous = is_array($previous) ? $previous : [];

        return collect($changes)
            ->map(function (mixed $new, string $field) use ($previous, $labels): string {
                $label = str($field)->replace('_', ' ')->toString();

                $show = fn (mixed $value) => $this->readable($labels[$field][$value] ?? $value);

                if (! array_key_exists($field, $previous)) {
                    return $label.': '.$show($new);
                }

                return $label.': '.$show($previous[$field]).' → '.$show($new);
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
