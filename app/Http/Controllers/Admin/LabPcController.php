<?php

namespace App\Http\Controllers\Admin;

use App\Enums\DeviceStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\LabPcRequest;
use App\Models\AuditLog;
use App\Models\LabPc;
use App\Models\Location;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

/**
 * Lab workstation inventory (FR-404).
 *
 * Retire, never delete: a machine's inspections and tickets must outlive it
 * leaving service (NFR-06). `destroy` soft-deletes and the record stays
 * reachable through the "include retired" filter.
 *
 * @see docs/modules/M2-locations-and-assets.md
 */
class LabPcController extends Controller
{
    public function index(Request $request): View
    {
        return view('admin.lab-pcs.index', [
            'labPcs' => LabPc::query()
                ->with('location')
                ->when($request->string('search')->trim()->value(), fn ($q, $term) => $q->where(
                    fn ($q) => $q->where('asset_tag', 'like', "%{$term}%")->orWhere('hostname', 'like', "%{$term}%")
                ))
                ->when($request->filled('location'), fn ($q) => $q->where('location_id', $request->integer('location')))
                ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
                /*
                 * Retired machines are hidden by default but never gone.
                 *
                 * Both halves of retirement are checked here. `withTrashed()`
                 * lifts the soft-delete scope; `inService()` covers a row whose
                 * status says retired without a deleted_at — which a seeder or
                 * a direct DB edit can produce even though `retire()` cannot.
                 */
                ->when($request->boolean('retired'),
                    fn ($q) => $q->withTrashed(),
                    fn ($q) => $q->inService())
                ->orderBy($this->sortColumn($request), $request->string('direction') === 'desc' ? 'desc' : 'asc')
                ->paginate(25)
                ->withQueryString(),
            'locations' => $this->locationOptions(),
            'statuses' => DeviceStatus::options(),
        ]);
    }

    public function create(): View
    {
        return view('admin.lab-pcs.create', [
            'locations' => $this->locationOptions(),
            'statuses' => $this->assignableStatuses(),
        ]);
    }

    public function store(LabPcRequest $request): RedirectResponse
    {
        $pc = LabPc::create($request->validated());

        return redirect()
            ->route('admin.lab-pcs.show', $pc)
            ->with('success', "{$pc->asset_tag} added to the inventory.");
    }

    public function show(LabPc $labPc): View
    {
        return view('admin.lab-pcs.show', [
            'labPc' => $labPc->load('location'),
            'events' => AuditLog::forSubject($labPc)->with('user')->latest('created_at')->limit(20)->get(),
        ]);
    }

    public function edit(LabPc $labPc): View
    {
        return view('admin.lab-pcs.edit', [
            'labPc' => $labPc,
            'locations' => $this->locationOptions(),
            'statuses' => $this->assignableStatuses(),
        ]);
    }

    public function update(LabPcRequest $request, LabPc $labPc): RedirectResponse
    {
        $labPc->update($request->validated());

        return redirect()
            ->route('admin.lab-pcs.show', $labPc)
            ->with('success', "{$labPc->asset_tag} updated.");
    }

    /**
     * Retire a machine. Never a hard delete.
     *
     * Retirement is two columns, always written together: the soft-delete
     * timestamp is what hides the record, and `status` is what every report,
     * export and M7 scoring run reads. Setting only one leaves a machine that
     * is retired according to the list and operational according to the
     * database, which is exactly the kind of disagreement a demo dies on.
     *
     * `status` must be updated BEFORE `delete()` — an update on a soft-deleted
     * model still works, but the audit observer would then record the status
     * change after the deletion, and the history would read backwards.
     */
    public function destroy(LabPc $labPc): RedirectResponse
    {
        $labPc->update(['status' => DeviceStatus::Retired]);
        $labPc->delete();

        return redirect()
            ->route('admin.lab-pcs.index')
            ->with('success', "{$labPc->asset_tag} retired. Its inspection and ticket history is kept.");
    }

    /**
     * The exact inverse of destroy(), so the two columns stay in step.
     *
     * The route carries ->withTrashed(); without it the binding would 404 on
     * precisely the records this action exists to reach.
     */
    public function restore(LabPc $labPc): RedirectResponse
    {
        $labPc->restore();
        $labPc->update(['status' => DeviceStatus::Operational]);

        return redirect()
            ->route('admin.lab-pcs.show', $labPc)
            ->with('success', "{$labPc->asset_tag} is back in service.");
    }

    /** @return array<int, string> */
    private function locationOptions(): array
    {
        return Location::orderBy('code')->get()->mapWithKeys(fn (Location $l) => [$l->id => $l->label()])->all();
    }

    /**
     * Statuses a person may pick on the form.
     *
     * Retired is missing on purpose. It is not a status you choose, it is what
     * the Retire button does — offering it in the dropdown would let somebody
     * mark a machine retired while it stays in every active list.
     *
     * @return array<string, string>
     */
    private function assignableStatuses(): array
    {
        return Arr::except(DeviceStatus::options(), [DeviceStatus::Retired->value]);
    }

    private function sortColumn(Request $request): string
    {
        // Whitelisted: an unchecked sort parameter is an ORDER BY injection.
        return in_array($request->string('sort')->value(), ['asset_tag', 'hostname', 'status', 'purchase_date'], true)
            ? $request->string('sort')->value()
            : 'asset_tag';
    }
}
