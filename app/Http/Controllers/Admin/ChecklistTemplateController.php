<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ChecklistCategory;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ChecklistTemplateRequest;
use App\Models\ChecklistTemplate;
use App\Services\Maintenance\ChecklistService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

/**
 * Checklist templates (FR-406, Q2).
 *
 * Items are edited as part of the template rather than through a controller
 * of their own: position in the submitted list IS `sort_order`, so saving one
 * item alone would have nothing to say about where it sits. One form, one
 * save, one atomic replace.
 *
 * The versioning rule lives in ChecklistService, not here. This controller
 * only ever asks it to apply an edit and shows whatever comes back — which
 * may be a different template from the one that was edited.
 *
 * @see docs/modules/M3-checklists-and-inspections.md
 */
class ChecklistTemplateController extends Controller
{
    public function __construct(private readonly ChecklistService $checklists) {}

    public function index(): View
    {
        return view('admin.checklists.index', [
            'templates' => ChecklistTemplate::query()
                ->withCount(['items', 'inspections'])
                ->orderByDesc('is_active')
                ->orderBy('name')
                ->orderByDesc('version')
                ->orderBy('id')
                ->paginate(20),
        ]);
    }

    public function create(): View
    {
        return view('admin.checklists.create', [
            'template' => null,
            'categories' => ChecklistCategory::options(),
        ]);
    }

    public function store(ChecklistTemplateRequest $request): RedirectResponse
    {
        // refresh(): version is a column default, and the label quotes it.
        $template = ChecklistTemplate::create($request->validated())->refresh();
        $this->checklists->replaceItems($template, $request->items());

        return redirect()
            ->route('admin.checklists.edit', $template)
            ->with('success', "{$template->label()} created. Activate it when you are ready for assistants to use it.");
    }

    public function edit(ChecklistTemplate $checklist): View
    {
        return view('admin.checklists.edit', [
            'template' => $checklist->load('items'),
            'categories' => ChecklistCategory::options(),
            // Drives the warning on the form: editing this one will produce a
            // new version rather than changing what is on screen.
            'isLocked' => $checklist->isLocked(),
            // What saving would create, from the highest version of this
            // name, as newVersionOf() counts: not this one plus one.
            'nextVersion' => $this->checklists->nextVersion($checklist->name),
        ]);
    }

    /**
     * Save an edit.
     *
     * If the template has been used, ChecklistService returns a NEW version
     * and this redirects there — the URL changes under the administrator on
     * purpose, because the thing they are now editing is genuinely a
     * different record. The flash message says so.
     */
    public function update(ChecklistTemplateRequest $request, ChecklistTemplate $checklist): RedirectResponse
    {
        $wasLocked = $checklist->isLocked();

        $saved = $this->checklists->update($checklist, $request->validated());
        $this->checklists->replaceItems($saved, $request->items());

        return redirect()
            ->route('admin.checklists.edit', $saved)
            ->with('success', $wasLocked
                ? "{$checklist->label()} has been used by an inspection, so your changes were saved as {$saved->label()}. The old version is kept so past inspections still read correctly."
                : "{$saved->label()} updated.");
    }

    public function activate(ChecklistTemplate $checklist): RedirectResponse
    {
        $this->checklists->activate($checklist);

        return redirect()
            ->route('admin.checklists.index')
            ->with('success', "{$checklist->label()} is now the checklist assistants will use.");
    }

    /**
     * Deleting is allowed only while nothing has used it.
     *
     * The database says the same thing — restrictOnDelete from
     * inspection_results to checklist_items — but a foreign key error is not
     * an explanation.
     */
    public function destroy(ChecklistTemplate $checklist): RedirectResponse
    {
        if ($checklist->isLocked()) {
            return back()->with('error', "{$checklist->label()} has been used by an inspection and cannot be deleted. Its history depends on it.");
        }

        if ($checklist->is_active) {
            return back()->with('error', "{$checklist->label()} is the active checklist. Activate another one first, or assistants will have nothing to fill in.");
        }

        $checklist->items()->delete();
        $checklist->delete();

        return redirect()
            ->route('admin.checklists.index')
            ->with('success', 'Checklist template deleted.');
    }
}
