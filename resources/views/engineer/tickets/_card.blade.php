{{--
    One ticket on a phone: the same facts as a table row. "Take it" sits
    under the card rather than inside it, because a form cannot go inside
    the link that opens the ticket.
--}}
<li class="overflow-hidden rounded-xl border border-line bg-surface shadow-xs">
    <a href="{{ route('engineer.tickets.show', $ticket) }}" class="block p-4 active:bg-sunken">
        <span class="flex items-center justify-between gap-2">
            <span class="font-mono text-xs text-fg-muted">{{ $ticket->ticket_number }}</span>
            <x-priority-pill :priority="$ticket->priority" />
        </span>
        <span class="mt-1 block font-medium text-fg">{{ $ticket->title }}</span>
        <span class="mt-1 block text-xs text-fg-muted">{{ $ticket->placeLabel() }}</span>
        <span class="mt-3 flex items-end justify-between gap-3">
            <x-status-badge :status="$ticket->status" />
            <x-sla-line :ticket="$ticket" class="max-w-[11rem]" />
        </span>
    </a>

    @if (! $ticket->assignee && $ticket->status->isOutstanding() && auth()->user()->can('assign', \App\Models\Ticket::class))
        <form method="POST" action="{{ route('tickets.assign', $ticket) }}"
              class="flex items-center justify-between gap-3 border-t border-line px-4 py-2.5">
            @csrf
            @method('PATCH')
            <input type="hidden" name="assigned_to" value="{{ auth()->id() }}">
            <span class="text-xs text-fg-subtle">Nobody has this yet</span>
            <x-button type="submit" variant="secondary" size="sm">Take it</x-button>
        </form>
    @endif
</li>
