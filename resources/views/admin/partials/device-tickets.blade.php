{{-- The tickets raised against one device, newest first. Included by the lab
     PC and access point detail screens with ['tickets' => Collection]. --}}
<x-card title="Tickets" subtitle="Faults raised against this device, newest first." flush>
    @if ($tickets->isEmpty())
        <x-empty-state title="No tickets" icon="ticket"
                       message="Nothing has been reported against this device." />
    @else
        <ul class="divide-y divide-line">
            @foreach ($tickets as $ticket)
                <li>
                    <a href="{{ route('engineer.tickets.show', $ticket) }}"
                       class="group flex items-center gap-4 px-5 py-3 transition hover:bg-sunken/60">
                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-sm font-medium text-fg group-hover:text-brand-700">{{ $ticket->title }}</span>
                            <span class="block truncate text-xs text-fg-muted">
                                <span class="font-mono">{{ $ticket->ticket_number }}</span>
                                · opened {{ $ticket->opened_at?->diffForHumans() }}
                                · {{ $ticket->assignee?->name ?? 'nobody on it' }}
                            </span>
                        </span>
                        <x-status-badge :status="$ticket->status" class="flex-none" />
                        <x-icon name="chevron-right" class="size-4 flex-none text-fg-subtle" />
                    </a>
                </li>
            @endforeach
        </ul>
    @endif
</x-card>
