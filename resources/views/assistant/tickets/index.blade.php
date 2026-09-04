<x-app-layout>
    <x-slot name="title">My tickets</x-slot>

    <x-page-header title="My tickets"
                   subtitle="Faults you have reported, and where each one has got to.">
        <x-slot:actions>
            <a href="{{ route('assistant.tickets.create') }}"
               class="inline-flex items-center gap-1.5 rounded-md bg-brand-600 px-3 py-2 text-sm font-medium text-white hover:bg-brand-700">
                <x-icon name="plus" class="h-4 w-4" /> Raise a ticket
            </a>
        </x-slot:actions>
    </x-page-header>

    {{-- ON A PHONE, A LIST OF CARDS. The assistant is the mobile-first role and
         this is one of their four screens, but a six-column table at 375px
         shows one column and hides the rest behind a sideways scroll nobody
         can see. Below md the same tickets render as cards; the table is for
         a desk. Same query, same pagination, same filters — see _filters. --}}
    <div id="mobile-ticket-list" class="md:hidden space-y-3">
        <div class="rounded-lg bg-white px-4 py-3 ring-1 ring-slate-200 shadow-sm">
            @include('assistant.tickets._filters')
        </div>

        @forelse ($tickets as $ticket)
            <a href="{{ route('assistant.tickets.show', $ticket) }}"
               class="block rounded-lg bg-white p-4 ring-1 ring-slate-200 shadow-sm hover:bg-slate-50
                      focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500">
                <div class="flex items-start justify-between gap-3">
                    <span class="font-mono text-xs font-medium text-slate-900">{{ $ticket->ticket_number }}</span>
                    <x-status-badge :status="$ticket->status" />
                </div>
                <p class="mt-1 text-sm font-medium text-slate-900">{{ $ticket->title }}</p>
                <p class="mt-1 text-xs text-slate-500">{{ $ticket->subjectLabel() }} · {{ $ticket->opened_at?->diffForHumans() }}</p>
                <div class="mt-2 flex items-center gap-2 text-xs text-slate-600">
                    <x-priority-pill :priority="$ticket->priority" />
                    <span>{{ $ticket->assignee?->name ?? 'Not picked up yet' }}</span>
                </div>
            </a>
        @empty
            <x-empty-state title="You have not reported anything yet"
                           message="Raise a ticket when you find a fault, or straight from a failed item on an inspection."
                           icon="ticket" />
        @endforelse

        @if ($tickets->hasPages())
            <x-pagination :paginator="$tickets" />
        @endif
    </div>

    <x-data-table :headers="['Ticket', 'Subject', 'Priority', 'Status', 'With', 'Raised']" class="max-md:hidden">
        <x-slot:filters>
            @include('assistant.tickets._filters')
        </x-slot:filters>

        @foreach ($tickets as $ticket)
            <tr class="hover:bg-slate-50">
                <td class="px-4 py-3">
                    <a href="{{ route('assistant.tickets.show', $ticket) }}"
                       class="font-mono text-xs font-medium text-slate-900 hover:text-brand-700">{{ $ticket->ticket_number }}</a>
                    <span class="block max-w-xs truncate text-sm text-slate-700">{{ $ticket->title }}</span>
                </td>
                <td class="px-4 py-3 text-sm text-slate-600">{{ $ticket->subjectLabel() }}</td>
                <td class="px-4 py-3"><x-priority-pill :priority="$ticket->priority" /></td>
                <td class="px-4 py-3"><x-status-badge :status="$ticket->status" /></td>
                <td class="px-4 py-3 text-sm text-slate-600">{{ $ticket->assignee?->name ?? 'Not picked up yet' }}</td>
                <td class="px-4 py-3 text-sm text-slate-600">{{ $ticket->opened_at?->diffForHumans() }}</td>
            </tr>
        @endforeach

        <x-slot:empty>
            <x-empty-state title="You have not reported anything yet"
                           message="Raise a ticket when you find a fault, or straight from a failed item on an inspection."
                           icon="ticket">
                <x-slot:action>
                    <a href="{{ route('assistant.tickets.create') }}" class="rounded-md bg-brand-600 px-3 py-2 text-sm font-medium text-white hover:bg-brand-700">Raise a ticket</a>
                </x-slot:action>
            </x-empty-state>
        </x-slot:empty>

        <x-slot:pagination><x-pagination :paginator="$tickets" /></x-slot:pagination>
    </x-data-table>
</x-app-layout>
