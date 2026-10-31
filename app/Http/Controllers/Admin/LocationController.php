<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\LocationRequest;
use App\Models\Location;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Campus places that house assets (FR-403).
 *
 * Reference data: locations ship with the system via ReferenceSeeder and are
 * never tagged is_demo. Authorisation is the `role:admin` middleware on the
 * route group — there is no per-record rule to enforce here.
 *
 * @see docs/modules/M2-locations-and-assets.md
 */
class LocationController extends Controller
{
    public function index(Request $request): View
    {
        return view('admin.locations.index', [
            'locations' => Location::query()
                ->withCount(['labPcs', 'accessPoints'])
                ->when($request->string('search')->trim()->value(), fn ($q, $term) => $q->where(
                    fn ($q) => $q->whereLike('name', "%{$term}%")->orWhereLike('code', "%{$term}%")
                ))
                ->when($request->filled('type'), fn ($q) => $q->where('type', $request->string('type')))
                ->orderBy('code')
                ->paginate(20)
                ->withQueryString(),
            'types' => $this->types(),
        ]);
    }

    public function create(): View
    {
        return view('admin.locations.create', ['types' => $this->types()]);
    }

    public function store(LocationRequest $request): RedirectResponse
    {
        $location = Location::create($request->validated());

        return redirect()
            ->route('admin.locations.index')
            ->with('success', "{$location->code} added.");
    }

    public function edit(Location $location): View
    {
        return view('admin.locations.edit', [
            'location' => $location,
            'types' => $this->types(),
        ]);
    }

    public function update(LocationRequest $request, Location $location): RedirectResponse
    {
        $location->update($request->validated());

        return redirect()
            ->route('admin.locations.index')
            ->with('success', "{$location->code} updated.");
    }

    /**
     * Deleting is allowed only while nothing lives here.
     *
     * The database enforces this too, with restrictOnDelete on lab_pcs,
     * network_devices, shift_templates and shifts, but a foreign key error
     * is not an explanation. This turns it into one.
     */
    public function destroy(Location $location): RedirectResponse
    {
        /*
         * withTrashed() is load-bearing. A retired lab PC still holds a
         * foreign key to this location, but the soft-delete scope hides it
         * from the plain relation — so counting without it would report the
         * location empty and then hand the user the raw FK error this method
         * exists to prevent.
         */
        $held = $location->labPcs()->withTrashed()->count()
            + $location->networkDevices()->withTrashed()->count()
            + $location->shiftTemplates()->count()
            + $location->shifts()->count();

        if ($held > 0) {
            // Named "records", not "assets", because the count deliberately
            // includes retired ones — otherwise the message reads as nonsense
            // next to a list showing this location with nothing in it.
            return back()->with('error', "{$location->code} still has {$held} ".str('record')->plural($held).' pointing at it: machines, devices, shift patterns or shifts, including any retired ones. Move them to another location first.');
        }

        $location->delete();

        return redirect()
            ->route('admin.locations.index')
            ->with('success', 'Location removed.');
    }

    /** @return array<string, string> */
    private function types(): array
    {
        return [
            'it_lab' => 'IT Lab',
            'library' => 'Library',
            'cafeteria' => 'Cafeteria',
            'other' => 'Other',
        ];
    }
}
