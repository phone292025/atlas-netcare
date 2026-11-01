<?php

namespace App\Http\Controllers;

use App\Enums\IncidentRule;
use App\Enums\IncidentStatus;
use App\Models\Incident;
use App\Services\Incidents\IncidentService;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The incidents screen, for every role (roadmap 2.2 to 2.5).
 *
 * @see docs/modules/M8-incidents.md
 */
class IncidentController extends Controller
{
    public function __construct(private readonly IncidentService $incidents) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Incident::class);

        $view = $request->string('view')->toString() === 'resolved' ? 'resolved' : 'active';
        $byStatus = Incident::query()->toBase()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        return view('incidents.index', [
            'view' => $view,
            'counts' => [
                'active' => (int) $byStatus->get(IncidentStatus::Open->value, 0) + (int) $byStatus->get(IncidentStatus::Acknowledged->value, 0),
                'open' => (int) $byStatus->get(IncidentStatus::Open->value, 0),
                'resolved' => (int) $byStatus->get(IncidentStatus::Resolved->value, 0),
            ],
            'incidents' => Incident::query()
                ->with(['device.location', 'agent', 'ticket', 'acknowledgedBy'])
                ->withCount(['devices as down_count' => fn ($q) => $q->whereNull('incident_devices.recovered_at')])
                ->when($view === 'active', fn ($q) => $q->unresolved()->orderByRaw('case when status = ? then 0 else 1 end', [IncidentStatus::Open->value]))
                ->when($view === 'resolved', fn ($q) => $q->whereNotNull('resolved_at'))
                ->when($request->filled('rule'), fn ($q) => $q->where('rule', IncidentRule::tryFrom($request->string('rule')->toString())))
                ->latest('opened_at')
                ->latest('id')
                ->paginate(25)
                ->withQueryString(),
            'rules' => IncidentRule::options(),
        ]);
    }

    public function show(Request $request, Incident $incident): View
    {
        $this->authorize('view', $incident);
        $user = $request->user();

        return view('incidents.show', [
            // Worked out here: the acknowledge rule reads who was alerted,
            // and views never query.
            'can' => [
                'acknowledge' => $user->can('acknowledge', $incident),
                'resolve' => $user->can('resolve', $incident),
                'raiseTicket' => $user->can('raiseTicket', $incident),
            ],
            'incident' => $incident->load(['device.location', 'device.parent', 'agent', 'ticket', 'acknowledgedBy', 'groupedInto', 'events.user', 'devices.location']),
            'alerts' => $incident->alerts()->with('user')->orderBy('step')->orderBy('id')->get(),
        ]);
    }

    public function acknowledge(Request $request, Incident $incident): RedirectResponse
    {
        $this->authorize('acknowledge', $incident);

        try {
            $this->incidents->acknowledge($incident, $request->user());
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Acknowledged. Nobody else will be told about it unless it gets worse.');
    }

    public function resolve(Request $request, Incident $incident): RedirectResponse
    {
        $this->authorize('resolve', $incident);

        $note = $request->validate(['note' => ['required', 'string', 'max:500']])['note'];

        try {
            $this->incidents->resolveByHand($incident, $request->user(), $note);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Resolved. It opens again if the device degrades again.');
    }

    public function ticket(Request $request, Incident $incident): RedirectResponse
    {
        $this->authorize('raiseTicket', $incident);

        try {
            $incident = $this->incidents->raiseTicket($incident, $request->user());
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "{$incident->ticket?->ticket_number} raised for this incident.");
    }
}
