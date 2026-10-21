<?php

namespace App\Http\Controllers\Engineer;

use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tickets\AssignTicketRequest;
use App\Http\Requests\Tickets\TransitionTicketRequest;
use App\Models\Location;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Tickets\TicketService;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The engineer's queue and the transitions that move a ticket along
 * (FR-306, FR-307).
 *
 * @see docs/modules/M4-ticketing.md
 */
class TicketController extends Controller
{
    public function __construct(private readonly TicketService $tickets) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Ticket::class);

        return view('engineer.tickets.index', [
            'tickets' => Ticket::query()
                ->with(['ticketable', 'location', 'reporter', 'assignee'])
                ->when($request->string('search')->trim()->value(), fn ($q, $term) => $q->where(
                    fn ($q) => $q->whereLike('ticket_number', "%{$term}%")
                        ->orWhereLike('title', "%{$term}%")
                ))
                ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
                ->when($request->filled('priority'), fn ($q) => $q->where('priority', $request->string('priority')))
                ->when($request->filled('location'), fn ($q) => $q->where('location_id', $request->integer('location')))
                ->when($request->filled('assignee'), fn ($q) => $q->where('assigned_to', $request->integer('assignee')))
                ->when($request->boolean('mine'), fn ($q) => $q->where('assigned_to', $request->user()->id))
                ->when($request->boolean('unassigned'), fn ($q) => $q->whereNull('assigned_to'))
                ->when($request->boolean('breaching'), fn ($q) => $q->breaching())
                // Default view is work that still needs somebody.
                ->when(! $request->hasAny(['status', 'search']), fn ($q) => $q->outstanding())
                ->mostUrgentFirst()
                ->paginate(25)
                ->withQueryString(),

            'statuses' => TicketStatus::options(),
            'priorities' => TicketPriority::options(),
            'locations' => Location::orderBy('code')->get()->mapWithKeys(fn ($l) => [$l->id => $l->label()])->all(),
            'engineers' => User::query()->active()->role(UserRole::Engineer)
                ->orderBy('name')->get()->mapWithKeys(fn ($u) => [$u->id => $u->name])->all(),
            'breachCount' => Ticket::query()->breaching()->count(),

            // The view tabs above the queue. One query per tab, a handful of
            // counts on an indexed table.
            'counts' => [
                'open' => Ticket::query()->outstanding()->count(),
                'mine' => Ticket::query()->outstanding()->where('assigned_to', $request->user()->id)->count(),
                'unassigned' => Ticket::query()->outstanding()->whereNull('assigned_to')->count(),
                'resolved' => Ticket::query()->where('status', TicketStatus::Resolved)->count(),
                'closed' => Ticket::query()->where('status', TicketStatus::Closed)->count(),
            ],
        ]);
    }

    public function show(Ticket $ticket): View
    {
        $this->authorize('view', $ticket);

        return view('engineer.tickets.show', [
            'ticket' => $ticket->load(['ticketable', 'location', 'reporter', 'assignee', 'inspection']),
            'comments' => $ticket->comments()->visibleTo(request()->user())->with('author')->get(),
            // Buttons render from the machine itself, so the UI can never
            // offer a move the service would then refuse.
            'nextStates' => $this->tickets->nextStates($ticket),

            /*
             * FR-307. Fetched here rather than in the view, which does not
             * query. Admins are included because TicketPolicy::assign()
             * admits them and an admin can legitimately hold a ticket.
             */
            'engineers' => User::query()->active()
                ->whereIn('role', [UserRole::Engineer->value, UserRole::Admin->value])
                ->orderBy('name')->pluck('name', 'id')->all(),
        ]);
    }

    /**
     * Move the ticket.
     *
     * Three checks, all of them server-side: the request shape, then the
     * policy (may this person?), then the service (is this move legal?).
     * Hiding a button is not a check.
     */
    public function transition(TransitionTicketRequest $request, Ticket $ticket): RedirectResponse
    {
        $target = $request->target();

        $this->authorize($this->abilityFor($target), $ticket);

        try {
            $this->tickets->transition($ticket, $target, $request->user(), [
                'resolution_notes' => $request->validated('resolution_notes'),
            ]);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "{$ticket->ticket_number} is now {$target->label()}.");
    }

    /**
     * Hand the ticket to an engineer, or put it back in the pool (FR-307).
     *
     * Not a transition. Who is doing the work and how far along it is are
     * different questions, and reassigning mid-repair must not move the ticket
     * backwards through its own lifecycle.
     */
    public function assign(AssignTicketRequest $request, Ticket $ticket): RedirectResponse
    {
        try {
            $ticket = $this->tickets->assign($ticket, $request->engineer(), $request->user());
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with(
            'success',
            $ticket->assignee
                ? "{$ticket->ticket_number} is now with {$ticket->assignee->name}."
                : "{$ticket->ticket_number} is back in the queue.",
        );
    }

    /** Which policy ability guards each destination state. */
    private function abilityFor(TicketStatus $target): string
    {
        return match ($target) {
            TicketStatus::Acknowledged => 'acknowledge',
            TicketStatus::InProgress, TicketStatus::Resolved => 'resolve',
            TicketStatus::Closed => 'close',
            TicketStatus::Reopened => 'reopen',
            default => 'work',
        };
    }
}
