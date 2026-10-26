<?php

namespace App\Observers;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

/**
 * Writes an audit row whenever an auditable model is created, changed or
 * removed.
 *
 * An observer rather than calls inside controllers, because a call site can be
 * forgotten. Register a model in AppServiceProvider and every write to it is
 * recorded, including from a seeder, a console command or tinker.
 *
 * Registered models are listed in AppServiceProvider::AUDITED.
 *
 * @see docs/01-requirements.md FR-509, NFR-06
 */
class AuditObserver
{
    public function created(Model $model): void
    {
        $this->record($model, 'created');
    }

    public function updated(Model $model): void
    {
        // getChanges() is what actually changed, not everything that was set.
        // A save that alters nothing should not produce a log row.
        $changes = collect($model->getChanges())
            ->except($this->neverLog($model))
            ->all();

        if ($changes === []) {
            return;
        }

        /*
         * What each of those was beforehand. Keyed off $changes rather than
         * off getRawOriginal() wholesale, so the exclusions above apply to
         * both halves — otherwise the OLD password hash would be written into
         * the audit log while the new one was correctly kept out, which is a
         * worse leak than the one the exclusion list exists to prevent.
         *
         * getRawOriginal, not getOriginal: getChanges() holds raw attribute
         * values, and a pair where one side is cast and the other is not would
         * render as "open changed to open".
         */
        $previous = collect($changes)
            ->map(fn ($value, string $key) => $model->getRawOriginal($key))
            ->all();

        $this->record($model, 'updated', $changes, $previous);
    }

    public function deleted(Model $model): void
    {
        // Soft deletes surface here too, which is what we want: deactivating
        // or retiring something is exactly the kind of act worth recording.
        $this->record($model, method_exists($model, 'trashed') ? 'archived' : 'deleted');
    }

    public function restored(Model $model): void
    {
        $this->record($model, 'restored');
    }

    /**
     * @param  array<string, mixed>|null  $changes  what the fields became
     * @param  array<string, mixed>|null  $previous  what they had been
     */
    private function record(
        Model $model,
        string $action,
        ?array $changes = null,
        ?array $previous = null,
    ): void {
        AuditLog::create([
            // Null for a seeder or a scheduled command — nobody was signed in.
            'user_id' => Auth::id(),
            'action' => $action,
            'auditable_type' => $model->getMorphClass(),
            'auditable_id' => $model->getKey(),
            'description' => $this->describe($model),
            'changes' => $changes,
            'previous' => $previous,
            'ip_address' => Request::ip(),
            'created_at' => now(),
        ]);
    }

    /**
     * Attributes that never reach an audit row.
     *
     * Secrets come from the model's own `$hidden`, where a developer already
     * has to declare one to keep it out of JSON, so the next secret column is
     * covered by the act of adding it. A hardcoded list here once missed
     * `users.calendar_token` and wrote live tokens into the log.
     *
     * Noise comes from the model's AUDIT_EXCEPT: system-written caches whose
     * history lives in their own table. `updated_at` changes on every save.
     * A save that changes nothing else writes no row at all.
     *
     * @return list<string>
     */
    private function neverLog(Model $model): array
    {
        $derived = defined($model::class.'::AUDIT_EXCEPT') ? $model::AUDIT_EXCEPT : [];

        return array_values(array_unique([
            'updated_at',
            ...$model->getHidden(),
            ...$derived,
        ]));
    }

    /**
     * Something a human can recognise in a list: an email, an asset tag, a
     * name, or the model's own label(), such as a shift's day and hours. Falls
     * back to the id when the model offers nothing better.
     */
    private function describe(Model $model): string
    {
        foreach (['email', 'asset_tag', 'code', 'name', 'title'] as $attribute) {
            if (filled($model->getAttribute($attribute))) {
                return (string) $model->getAttribute($attribute);
            }
        }

        if (method_exists($model, 'label')) {
            return $model->label();
        }

        return '#'.$model->getKey();
    }
}
