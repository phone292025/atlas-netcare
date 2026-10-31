<?php

namespace App\Models;

use App\Support\Tone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Carbon;

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

    /**
     * The tone of this entry's action, for a badge or a timeline dot.
     * The observer writes 'archived' for a soft delete and 'deleted' for a
     * hard one, so both are urgent.
     */
    public function tone(): string
    {
        return match ($this->action) {
            'created', 'restored' => Tone::HEALTHY,
            'archived', 'deleted' => Tone::URGENT,
            default => Tone::ATTENTION,
        };
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
     * Foreign-key columns shown as a name instead of a number: column => the
     * model it points at and the attribute to show. An explicit list rather
     * than a guess from an `_id` suffix, because `ticketable_id` is
     * polymorphic and a number beats a wrong name.
     *
     * @var array<string, array{class-string<Model>, string}>
     */
    private const LABELLED = [
        'assigned_to' => [User::class, 'name'],
        'reported_by' => [User::class, 'name'],
        'user_id' => [User::class, 'name'],
        'location_id' => [Location::class, 'code'],
    ];

    /**
     * Names for every foreign key across a page of audit rows, one query per
     * model rather than one per row.
     *
     * Resolved at READ time, so `changes` stays a record of what was actually
     * written to the column; a renamed person shows under their current name.
     * Soft-deleted users are included.
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
     * One readable line per changed field: "priority: low → critical", so the
     * audit screen prints strings and decides nothing.
     *
     * `getAttribute('changes')`, never `$this->changes`: inside the model that
     * reads Eloquent's own protected dirty-tracking property of the same name,
     * not the column.
     *
     * A row with no `previous` (written before 2026-09-04) shows only the new
     * value. `$labels` from labelsFor() turns ids into names; without it the
     * numbers still render.
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
        $casts = $this->subjectCasts();

        return collect($changes)
            ->map(function (mixed $new, string $field) use ($previous, $labels, $casts): string {
                $label = str($field)->replace('_', ' ')->toString();

                $show = fn (mixed $value) => $this->readable($labels[$field][$value] ?? $value, $casts[$field] ?? null);

                if (! array_key_exists($field, $previous)) {
                    return $label.': '.$show($new);
                }

                return $label.': '.$show($previous[$field]).' → '.$show($new);
            })
            ->values()
            ->all();
    }

    /**
     * The subject model's casts, so a stored moment can be shown as one.
     * Cached per class: a page of fifty rows is a handful of models.
     *
     * @return array<string, string>
     */
    private function subjectCasts(): array
    {
        static $cache = [];

        $class = Relation::getMorphedModel($this->auditable_type) ?? $this->auditable_type;

        return $cache[$class] ??= is_string($class) && is_subclass_of($class, Model::class)
            ? (new $class)->getCasts()
            : [];
    }

    /**
     * A value a person can read, short enough for a table cell. A moment is
     * stored in UTC and shown on the campus clock, like everywhere else: a
     * check-in at 11:16 read as 03:16.
     */
    private function readable(mixed $value, ?string $cast = null): string
    {
        return match (true) {
            $value === null, $value === '' => 'empty',
            is_string($value) && in_array($cast, ['datetime', 'immutable_datetime'], true) => Carbon::parse($value, 'UTC')->campus()->format('j M Y, H:i'),
            is_bool($value) => $value ? 'yes' : 'no',
            is_array($value) => '('.count($value).' items)',
            default => str((string) $value)->limit(40)->toString(),
        };
    }
}
