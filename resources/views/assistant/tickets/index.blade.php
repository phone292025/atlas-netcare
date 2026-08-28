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

    <x-data-table :headers="['Ticket', 'Subject', 'Priority', 'Status', 'With', 'Raised']">
        <x-slot:filters>
            <form method="GET" class="flex flex-wrap items-end gap-2">
                <div class="w-44">
                    <x-form.select name="status" label="Status" blank="Any" :options="$statuses" :selected="request('status')" />
                </div>
                <label class="flex items-center gap-2 py-2 text-sm text-slate-600">
                    <input type="checkbox" name="outstanding" value="1" @checked(request()->boolean('outstanding'))
                           class="rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                    Still open only
                </label>
                <button type="submit" class="rounded-md bg-white px-3 py-2 text-sm font-medium text-slate-700 ring-1 ring-slate-300 hover:bg-slate-50">Filter</button>
                @if (request()->hasAny(['status', 'outstanding']))
                    <a href="{{ route('assistant.tickets.index') }}" class="px-2 py-2 text-sm text-slate-500 underline">Clear</a>
                @endif
            </form>
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
