<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ApStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AccessPointRequest;
use App\Models\AccessPoint;
use App\Models\AuditLog;
use App\Models\Location;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Access point inventory (FR-405).
 *
 * The show page is where M5 will surface live telemetry and M7 the risk
 * factors, so it is laid out with room for both rather than as a bare
 * attribute list.
 *
 * @see docs/modules/M2-locations-and-assets.md
 */
class AccessPointController extends Controller
{
    public function index(Request $request): View
    {
        return view('admin.access-points.index', [
            'accessPoints' => AccessPoint::query()
                ->with('location')
                ->when($request->string('search')->trim()->value(), fn ($q, $term) => $q->where(
                    fn ($q) => $q->whereLike('ap_code', "%{$term}%")
                        ->orWhereLike('mac_address', "%{$term}%")
                        ->orWhereLike('ip_address', "%{$term}%")
                ))
                ->when($request->filled('location'), fn ($q) => $q->where('location_id', $request->integer('location')))
                ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
                /*
                 * Decommissioned access points are hidden but never gone —
                 * their telemetry history has to stay readable (NFR-06).
                 *
                 * Unlike a lab PC there is no matching status case: ApStatus
                 * describes what the radio is doing, and Maintenance means
                 * "temporarily off", not "gone". So for an AP, removal is the
                 * soft delete and nothing else.
                 */
                ->when($request->boolean('removed'), fn ($q) => $q->withTrashed())
                ->orderBy($this->sortColumn($request), $request->string('direction')->value() === 'desc' ? 'desc' : 'asc')
                ->paginate(25)
                ->withQueryString(),
            'locations' => $this->locationOptions(),
            'statuses' => ApStatus::options(),
        ]);
    }

    public function create(): View
    {
        return view('admin.access-points.create', [
            'locations' => $this->locationOptions(),
            'statuses' => ApStatus::options(),
        ]);
    }

    public function store(AccessPointRequest $request): RedirectResponse
    {
        $ap = AccessPoint::create($request->validated());

        return redirect()
            ->route('admin.aps.show', $ap)
            ->with('success', "{$ap->ap_code} added to the inventory.");
    }

    public function show(AccessPoint $accessPoint): View
    {
        return view('admin.access-points.show', [
            'accessPoint' => $accessPoint->load('location'),
            'events' => AuditLog::forSubject($accessPoint)->with('user')->latest('created_at')->limit(20)->get(),
            // Newest first, open or not: a device that keeps coming back is the
            // pattern worth seeing here.
            'tickets' => $accessPoint->tickets()->with('assignee')->latest('opened_at')->limit(10)->get(),
        ]);
    }

    public function edit(AccessPoint $accessPoint): View
    {
        return view('admin.access-points.edit', [
            'accessPoint' => $accessPoint,
            'locations' => $this->locationOptions(),
            'statuses' => ApStatus::options(),
        ]);
    }

    public function update(AccessPointRequest $request, AccessPoint $accessPoint): RedirectResponse
    {
        $accessPoint->update($request->validated());

        return redirect()
            ->route('admin.aps.show', $accessPoint)
            ->with('success', "{$accessPoint->ap_code} updated.");
    }

    public function destroy(AccessPoint $accessPoint): RedirectResponse
    {
        $accessPoint->delete();

        return redirect()
            ->route('admin.aps.index')
            ->with('success', "{$accessPoint->ap_code} removed. Its telemetry history is kept.");
    }

    public function restore(AccessPoint $accessPoint): RedirectResponse
    {
        $accessPoint->restore();

        return redirect()
            ->route('admin.aps.show', $accessPoint)
            ->with('success', "{$accessPoint->ap_code} is back in the inventory.");
    }

    /** @return array<int, string> */
    private function locationOptions(): array
    {
        return Location::orderBy('code')->get()->mapWithKeys(fn (Location $l) => [$l->id => $l->label()])->all();
    }

    private function sortColumn(Request $request): string
    {
        return in_array($request->string('sort')->value(), ['ap_code', 'status', 'client_capacity', 'last_seen_at'], true)
            ? $request->string('sort')->value()
            : 'ap_code';
    }
}
