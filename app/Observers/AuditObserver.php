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
 * @see docs/03-requirements.md FR-509, NFR-06
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
            ->except(['updated_at', 'remember_token', 'password'])
            ->all();

        if ($changes === []) {
            return;
        }

        $this->record($model, 'updated', $changes);
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

    /** @param  array<string, mixed>|null  $changes */
    private function record(Model $model, string $action, ?array $changes = null): void
    {
        AuditLog::create([
            // Null for a seeder or a scheduled command — nobody was signed in.
            'user_id' => Auth::id(),
            'action' => $action,
            'auditable_type' => $model->getMorphClass(),
            'auditable_id' => $model->getKey(),
            'description' => $this->describe($model),
            'changes' => $changes,
            'ip_address' => Request::ip(),
            'created_at' => now(),
        ]);
    }

    /**
     * Something a human can recognise in a list: an email, an asset tag, a
     * name. Falls back to the id when the model offers nothing better.
     */
    private function describe(Model $model): string
    {
        foreach (['email', 'asset_tag', 'ap_code', 'code', 'name', 'title'] as $attribute) {
            if (filled($model->getAttribute($attribute))) {
                return (string) $model->getAttribute($attribute);
            }
        }

        return '#'.$model->getKey();
    }
}
