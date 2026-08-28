<?php

namespace App\Http\Controllers\Assistant;

use App\Enums\TicketCategory;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tickets\StoreTicketRequest;
use App\Models\Inspection;
use App\Models\LabPc;
use App\Models\Location;
use App\Models\Ticket;
use App\Services\Tickets\TicketService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * An assistant raising and following their own tickets (FR-206, FR-207,
 * FR-208).
 *
 * @see docs/modules/M4-ticketing.md
 */
class TicketController extends Controller
{
    public function __construct(private readonly TicketService $tickets) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Ticket::class);

        return view('assistant.tickets.index', [
            'tickets' => Ticket::query()
                ->with(['ticketable', 'location', 'assignee'])
                ->reportedBy($request->user())
                ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
                ->when($request->boolean('outstanding'), fn ($q) => $q->outstanding())
                ->latest('opened_at')
                ->paginate(20)
                ->withQueryString(),
            'statuses' => TicketStatus::options(),
        ]);
    }

    /**
     * The raise-ticket form.
     *
     * FR-207: arriving from a failed checklist item pre-fills the asset, the
     * location and the fault, so the assistant re-types nothing. That is the
     * hand-off M3 built the panel for.
     */
    public function create(Request $request): View
    {
        $this->authorize('create', Ticket::class);

        $prefill = $this->prefillFromInspection($request);

        return view('assistant.tickets.create', [
            'categories' => TicketCategory::options(),
            'priorities' => TicketPriority::options(),
            'locations' => Location::orderBy('code')->get()->mapWithKeys(fn ($l) => [$l->id => $l->label()])->all(),
            'labPcs' => LabPc::query()->inService()->orderBy('asset_tag')
                ->get()->mapWithKeys(fn ($pc) => [$pc->id => $pc->asset_tag])->all(),
            'prefill' => $prefill,
        ]);
    }

    public function store(StoreTicketRequest $request): RedirectResponse
    {
        $this->authorize('create', Ticket::class);

        $ticket = $this->tickets->open(
            $request->ticketAttributes(),
            $request->user(),
            $request->subject(),
        );

        return redirect()
            ->route('assistant.tickets.show', $ticket)
            ->with('success', "{$ticket->ticket_number} raised. An engineer will pick it up.");
    }

    public function show(Ticket $ticket): View
    {
        $this->authorize('view', $ticket);

        return view('assistant.tickets.show', [
            'ticket' => $ticket->load(['ticketable', 'location', 'reporter', 'assignee']),
            // Scoped in the query, never filtered in Blade — see
            // TicketComment::scopeVisibleTo().
            'comments' => $ticket->comments()->visibleTo(request()->user())->with('author')->get(),
            'nextStates' => $this->tickets->nextStates($ticket),
        ]);
    }

    /**
     * Pull the asset and fault text out of a failed checklist item.
     *
     * @return array<string, mixed>
     */
    private function prefillFromInspection(Request $request): array
    {
        if (! $request->filled('inspection')) {
            return [];
        }

        $inspection = Inspection::with(['labPc', 'results.item'])->find($request->integer('inspection'));

        if (! $inspection || $inspection->user_id !== $request->user()->id) {
            // Somebody else's inspection, or none. Fall back to a blank form
            // rather than 403ing — the assistant can still raise the ticket by
            // hand, and refusing outright helps nobody.
            return [];
        }

        $result = $inspection->results->firstWhere('checklist_item_id', $request->integer('item'));

        return [
            'title' => $result?->item?->label,
            'description' => $result?->remark,
            'ticketable_type' => 'lab_pc',
            'ticketable_id' => $inspection->lab_pc_id,
            'location_id' => $inspection->labPc?->location_id,
            'inspection_id' => $inspection->id,
            // A critical checklist item failing is what makes an inspection a
            // Fail, so it arrives suggesting the matching priority. The
            // assistant can still change it.
            'priority' => $result?->item?->is_critical
                ? TicketPriority::Critical->value
                : TicketPriority::Medium->value,
            'category' => TicketCategory::Hardware->value,
        ];
    }
}
