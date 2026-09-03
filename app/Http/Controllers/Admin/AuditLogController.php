<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * The audit trail, read-only (FR-409).
 *
 * There is no create, edit or delete here on purpose. A log somebody can edit
 * is not evidence of anything.
 *
 * @see docs/modules/M1-identity-and-audit.md
 */
class AuditLogController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('viewAny', User::class);

        $logs = AuditLog::query()
            ->with('user')
            ->when($request->filled('action'), fn ($q) => $q->where('action', $request->string('action')))
            ->when($request->filled('type'), fn ($q) => $q->where('auditable_type', $request->string('type')))
            ->when($request->filled('user'), fn ($q) => $q->where('user_id', $request->integer('user')))
            ->latest('created_at')
            ->paginate(50)
            ->withQueryString();

        return view('admin.audit.index', [
            'logs' => $logs,

            // Names for the foreign keys on this page, resolved in one query
            // per model. Without it the log reads "assigned to: empty → 5".
            'labels' => AuditLog::labelsFor($logs),
            'actions' => AuditLog::query()->distinct()->orderBy('action')->pluck('action', 'action')->all(),
            'types' => AuditLog::query()->distinct()->orderBy('auditable_type')->pluck('auditable_type')
                ->mapWithKeys(fn ($t) => [$t => str(class_basename($t))->headline()->toString()])->all(),
        ]);
    }
}
