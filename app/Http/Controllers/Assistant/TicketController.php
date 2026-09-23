<?php

namespace App\Http\Controllers\Assistant;

use App\Enums\RiskBand;
use App\Enums\TicketCategory;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tickets\StoreTicketRequest;
use App\Models\AccessPoint;
use App\Models\Inspection;
use App\Models\LabPc;
use App\Models\Location;
use App\Models\RiskAssessment;
use App\Models\Ticket;
use App\Services\Tickets\TicketService;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

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
    public function create(Request $request): View|RedirectResponse
    {
        $this->authorize('create', Ticket::class);

        // Two ways a ticket arrives pre-filled: from a failed checklist item
        // (M3) or from a high-risk asset (M7, FR-311). Neither can be present
        // at once, so the first that matches wins.
        $prefill = $this->prefillFromInspection($request)
            ?: $this->prefillFromRiskyAsset($request);

        // A fault already ticketed goes back to the inspection, which links
        // to the ticket, rather than to a form for a second one.
        if ($existing = $this->tickets->raisedFromCheck($prefill['inspection_result_id'] ?? null)) {
            return redirect()
                ->route('assistant.inspections.show', $prefill['inspection_id'])
                ->with('warning', "That fault was already raised as {$existing->ticket_number}.");
        }

        return view('assistant.tickets.create', [
            'categories' => TicketCategory::options(),
            'priorities' => TicketPriority::options(),
            'locations' => Location::orderBy('code')->get()->mapWithKeys(fn ($l) => [$l->id => $l->label()])->all(),
            // Grouped by location, so a 250-machine estate is a short list
            // under the room you are standing in rather than one long select.
            // <x-form.select> renders a nested map as <optgroup>s.
            'labPcs' => LabPc::query()->inService()->with('location')->orderBy('asset_tag')->get()
                ->groupBy(fn (LabPc $pc) => $pc->location?->label() ?? 'No location')
                ->sortKeys()
                ->map(fn ($group) => $group->mapWithKeys(fn (LabPc $pc) => [$pc->id => $pc->asset_tag])->all())
                ->all(),
            'prefill' => $prefill,
        ]);
    }

    public function store(StoreTicketRequest $request): RedirectResponse
    {
        $this->authorize('create', Ticket::class);

        try {
            $ticket = $this->tickets->open(
                $request->ticketAttributes(),
                $request->user(),
                $request->subject(),
            );
        } catch (DomainException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        $this->tickets->announce($ticket);

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
            // Checklist items are worded as what should be true, so the bare
            // label read as the opposite of the fault in the queue.
            'title' => $result?->item ? Str::limit('Failed check: '.$result->item->label, 159, '…') : null,
            'description' => $result?->remark,
            'ticketable_type' => 'lab_pc',
            'ticketable_id' => $inspection->lab_pc_id,
            'location_id' => $inspection->labPc?->location_id,
            'inspection_id' => $inspection->id,
            'inspection_result_id' => $result?->id,
            // A critical checklist item failing is what makes an inspection a
            // Fail, so it arrives suggesting the matching priority. The
            // assistant can still change it.
            'priority' => $result?->item?->is_critical
                ? TicketPriority::Critical->value
                : TicketPriority::Medium->value,
            'category' => TicketCategory::Hardware->value,
        ];
    }

    /**
     * Pre-fill a PREVENTIVE ticket from an asset the risk model has flagged
     * (FR-311), with the actual reasons in the description.
     *
     * The asset is locked rather than offered as a dropdown: the engineer came
     * from that machine's detail screen. This is also the only way to ticket
     * an access point through the form, which otherwise offers the lab PCs an
     * assistant is standing at.
     *
     * @return array<string, mixed>
     */
    private function prefillFromRiskyAsset(Request $request): array
    {
        if (! $request->filled('asset') || ! $request->filled('asset_id')) {
            return [];
        }

        $asset = match ($request->string('asset')->toString()) {
            'lab-pc' => LabPc::find($request->integer('asset_id')),
            'access-point' => AccessPoint::find($request->integer('asset_id')),
            default => null,
        };

        if (! $asset) {
            // Fall back to a blank form rather than 403ing. The engineer can
            // still raise the ticket by hand, and refusing outright helps
            // nobody.
            return [];
        }

        $assessment = $asset->latestRiskAssessment;
        $isPc = $asset instanceof LabPc;
        $name = $isPc ? $asset->asset_tag : $asset->ap_code;

        return [
            'title' => "Preventive maintenance: {$name}",
            'description' => $this->riskNarrative($assessment),
            'ticketable_type' => $isPc ? 'lab_pc' : 'access_point',
            'ticketable_id' => $asset->id,
            'ticketable_label' => $name,
            'ticketable_locked' => true,
            'location_id' => $asset->location_id,
            'category' => ($isPc ? TicketCategory::Hardware : TicketCategory::Network)->value,
            // A suggestion, not a decision. A Critical band means the model
            // thinks this is close; the engineer looking at it knows more.
            'priority' => match ($asset->risk_band) {
                RiskBand::Critical => TicketPriority::Critical->value,
                RiskBand::High => TicketPriority::Medium->value,
                default => TicketPriority::Low->value,
            },
        ];
    }

    /**
     * The score, written out as the sentence a person would say.
     *
     * "78, mostly age and inspection lag" is what makes this a ticket somebody
     * can act on rather than a number they have to go and look up.
     */
    private function riskNarrative(?RiskAssessment $assessment): string
    {
        if (! $assessment) {
            return 'Raised from the maintenance priority ranking.';
        }

        $reasons = collect($assessment->topFactors(3))
            ->map(fn (float $points, string $name) => sprintf('%s (%.1f)', str_replace('_', ' ', $name), $points))
            ->implode(', ');

        return sprintf(
            "Raised from the maintenance priority ranking.\n\nRisk score %s (%s), calculated %s.\n\nHeaviest factors: %s.",
            round($assessment->score, 1),
            $assessment->band->label(),
            $assessment->calculated_at->campus()->format('j M Y'),
            $reasons ?: 'none measurable',
        );
    }
}
