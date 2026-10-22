{{--
    One ticket in the queue table, shared by the open list and "Finished this
    week". The whole row opens the ticket. A ticket nobody has carries a
    "Take it" button, whose click stops at its form, so taking a ticket does
    not also open it.
--}}
@php($url = route('engineer.tickets.show', $ticket))

<tr class="group cursor-pointer transition-colors hover:bg-sunken/70" onclick="window.location='{{ $url }}'">
    <td class="max-w-md px-4 py-3">
        <a href="{{ $url }}" class="block font-mono text-xs text-fg-muted group-hover:text-brand-700">{{ $ticket->ticket_number }}</a>
        <span class="mt-0.5 block truncate font-medium text-fg">{{ $ticket->title }}</span>
        <span class="mt-0.5 block truncate text-xs text-fg-muted">{{ $ticket->placeLabel() }}</span>
    </td>
    <td class="px-4 py-3"><x-priority-pill :priority="$ticket->priority" /></td>
    <td class="px-4 py-3"><x-status-badge :status="$ticket->status" /></td>
    <td class="px-4 py-3">
        @if ($ticket->assignee)
            <span class="flex items-center gap-2">
                <x-avatar :name="$ticket->assignee->name" />
                <span class="truncate text-fg">{{ $ticket->assignee->name }}</span>
            </span>
        @elseif ($ticket->status->isOutstanding() && auth()->user()->can('assign', \App\Models\Ticket::class))
            <form method="POST" action="{{ route('tickets.assign', $ticket) }}" onclick="event.stopPropagation()">
                @csrf
                @method('PATCH')
                <input type="hidden" name="assigned_to" value="{{ auth()->id() }}">
                <x-button type="submit" variant="secondary" size="sm">Take it</x-button>
            </form>
        @else
            <span class="text-fg-subtle">Nobody</span>
        @endif
    </td>
    <td class="px-4 py-3"><x-sla-line :ticket="$ticket" /></td>
    <td class="px-4 py-3 text-right">
        <x-icon name="chevron-right" class="size-4 text-fg-subtle transition group-hover:translate-x-0.5 group-hover:text-fg" />
    </td>
</tr>
