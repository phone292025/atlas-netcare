<?php

namespace App\Http\Controllers\Admin;

use App\Enums\DeviceKind;
use App\Enums\NetworkStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\NetworkDeviceRequest;
use App\Models\Agent;
use App\Models\AuditLog;
use App\Models\Location;
use App\Models\NetworkDevice;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Switches, routers, servers and other devices on the network (ADR-0016).
 *
 * Access points keep their own inventory, with the capacity and MAC address
 * only they need. Both share the table, the codes and the parent link: an
 * access point is connected to a device registered here.
 *
 * @see docs/04-database.md — network_devices
 */
class NetworkDeviceController extends Controller
{
    public function index(Request $request): View
    {
        return view('admin.network-devices.index', [
            'devices' => NetworkDevice::query()
                ->where('kind', '!=', DeviceKind::AccessPoint->value)
                ->with(['location', 'parent'])
                ->withCount('children')
                ->when($request->string('search')->trim()->value(), fn ($q, $term) => $q->where(
                    fn ($q) => $q->whereLike('code', "%{$term}%")
                        ->orWhereLike('name', "%{$term}%")
                        ->orWhereLike('ip_address', "%{$term}%")
                ))
                ->when($request->filled('kind'), fn ($q) => $q->where('kind', $request->string('kind')))
                ->when($request->filled('location'), fn ($q) => $q->where('location_id', $request->integer('location')))
                // Retired devices are hidden, never gone: their readings and
                // tickets stay readable.
                ->when($request->boolean('removed'), fn ($q) => $q->withTrashed())
                ->orderBy($this->sortColumn($request), $request->string('direction')->value() === 'desc' ? 'desc' : 'asc')
                ->orderBy('id')
                ->paginate(25)
                ->withQueryString(),
            'kinds' => DeviceKind::nonAccessPointOptions(),
            'locations' => $this->locationOptions(),
        ]);
    }

    public function create(): View
    {
        return view('admin.network-devices.create', $this->formData(null));
    }

    public function store(NetworkDeviceRequest $request): RedirectResponse
    {
        $device = NetworkDevice::create($request->validated());

        return redirect()
            ->route('admin.devices.show', $device)
            ->with('success', "{$device->code} added to the inventory.");
    }

    public function show(NetworkDevice $networkDevice): View
    {
        $this->refuseAccessPoint($networkDevice);

        return view('admin.network-devices.show', [
            'device' => $networkDevice->load(['location', 'parent', 'agent']),
            // Access points included: they are what hangs from a switch.
            'children' => $networkDevice->children()->with('location')->orderBy('code')->get(),
            'events' => AuditLog::forSubject($networkDevice)->with('user')->latest('created_at')->latest('id')->limit(20)->get(),
            'tickets' => $networkDevice->tickets()->with('assignee')->latest('opened_at')->limit(10)->get(),
        ]);
    }

    public function edit(NetworkDevice $networkDevice): View
    {
        $this->refuseAccessPoint($networkDevice);

        return view('admin.network-devices.edit', $this->formData($networkDevice));
    }

    public function update(NetworkDeviceRequest $request, NetworkDevice $networkDevice): RedirectResponse
    {
        $this->refuseAccessPoint($networkDevice);

        $networkDevice->update($request->validated());

        return redirect()
            ->route('admin.devices.show', $networkDevice)
            ->with('success', "{$networkDevice->code} updated.");
    }

    /**
     * Retiring is refused while anything is connected to the device. The
     * devices below it would keep pointing at a parent nobody can see, and
     * Phase 2 would group their faults under it.
     */
    public function destroy(NetworkDevice $networkDevice): RedirectResponse
    {
        $this->refuseAccessPoint($networkDevice);

        $connected = $networkDevice->children()->orderBy('code')->pluck('code');

        if ($connected->isNotEmpty()) {
            $names = $connected->take(3)->implode(', ').($connected->count() > 3 ? ' and '.($connected->count() - 3).' more' : '');

            return back()->with('error', "{$networkDevice->code} still has {$names} connected to it. Connect them to another device first.");
        }

        $networkDevice->delete();

        return redirect()
            ->route('admin.devices.index')
            ->with('success', "{$networkDevice->code} retired. Its history is kept.");
    }

    public function restore(NetworkDevice $networkDevice): RedirectResponse
    {
        $this->refuseAccessPoint($networkDevice);

        $networkDevice->restore();

        return redirect()
            ->route('admin.devices.show', $networkDevice)
            ->with('success', "{$networkDevice->code} is back in the inventory.");
    }

    /**
     * An access point has its own screens, which ask for what only it has.
     * Its id reaches this controller only by someone typing the address.
     */
    private function refuseAccessPoint(NetworkDevice $device): void
    {
        abort_if($device->kind === DeviceKind::AccessPoint, 404);
    }

    /** @return array<string, mixed> */
    private function formData(?NetworkDevice $device): array
    {
        return [
            'device' => $device,
            'kinds' => DeviceKind::nonAccessPointOptions(),
            'locations' => $this->locationOptions(),
            'parents' => NetworkDevice::parentChoices($device),
            'agents' => Agent::choices(),
            'statuses' => NetworkStatus::options(),
        ];
    }

    /** @return array<int, string> */
    private function locationOptions(): array
    {
        return Location::orderBy('code')->get()->mapWithKeys(fn (Location $l) => [$l->id => $l->label()])->all();
    }

    private function sortColumn(Request $request): string
    {
        return in_array($request->string('sort')->value(), ['code', 'kind', 'status', 'last_seen_at'], true)
            ? $request->string('sort')->value()
            : 'code';
    }
}
