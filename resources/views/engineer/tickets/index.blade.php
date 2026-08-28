<x-app-layout>
    <x-slot name="title">Ticket queue</x-slot>

    <x-page-header title="Ticket queue"
                   subtitle="{{ $tickets->total() }} {{ Str::plural('ticket', $tickets->total()) }}. Highest priority first, then whatever is due soonest.">
        <x-slot:actions>
            @if ($breachCount > 0)
                <a href="{{ route('engineer.tickets.index', ['breaching' => 1]) }}"
                   class="inline-flex items-center gap-1.5 rounded-md bg-red-50 px-3 py-2 text-sm font-medium text-red-700 ring-1 ring-inset ring-red-600/20 hover:bg-red-100">
                    <x-icon name="warning" class="h-4 w-4" />
                    {{ $breachCount }} past due
                </a>
            @endif
        </x-slot:actions>
    </x-page-header>

    <x-data-table :headers="[
        'Ticket',
        'Subject',
        'Priority',
        'Status',
        'Assigned to',
        'Due',
        '',
    ]">
        <x-slot:filters>
            <form method="GET" class="flex flex-wrap items-end gap-2">
                <div class="w-44">
                    <x-form.input name="search" label="Search" :value="request('search')" placeholder="Number or title" />
                </div>
                <div class="w-40">
                    <x-form.select name="status" label="Status" blank="Outstanding" :options="$statuses" :selected="request('status')" />
                </div>
                <div class="w-36">
                    <x-form.select name="priority" label="Priority" blank="Any" :options="$priorities" :selected="request('priority')" />
                </div>
                <div class="w-48">
                    <x-form.select name="location" label="Location" blank="Anywhere" :options="$locations" :selected="request('location')" />
                </div>
                <div class="w-44">
                    <x-form.select name="assignee" label="Engineer" blank="Anyone" :options="$engineers" :selected="request('assignee')" />
                </div>
                <label class="flex items-center gap-2 py-2 text-sm text-slate-600">
                    <input type="checkbox" name="mine" value="1" @checked(request()->boolean('mine'))
                           class="rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                    Mine
                </label>
                <button type="submit" class="rounded-md bg-white px-3 py-2 text-sm font-medium text-slate-700 ring-1 ring-slate-300 hover:bg-slate-50">Filter</button>
                @if (request()->hasAny(['search', 'status', 'priority', 'location', 'assignee', 'mine', 'breaching']))
                    <a href="{{ route('engineer.tickets.index') }}" class="px-2 py-2 text-sm text-slate-500 underline">Clear</a>
                @endif
            </form>
        </x-slot:filters>

        @foreach ($tickets as $ticket)
            <tr class="hover:bg-slate-50">
                <td class="px-4 py-3">
                    <a href="{{ route('engineer.tickets.show', $ticket) }}"
                       class="font-mono text-xs font-medium text-slate-900 hover:text-brand-700">{{ $ticket->ticket_number }}</a>
                    <span class="block max-w-xs truncate text-sm text-slate-700">{{ $ticket->title }}</span>
                </td>
                <td class="px-4 py-3">
                    {{-- nowrap: an asset tag is one token. Letting "APU-1801"
                         break after the hyphen makes it unreadable at a glance,
                         which is the only thing this column is for. --}}
                    <span class="block whitespace-nowrap text-sm text-slate-700">{{ $ticket->subjectLabel() }}</span>
                    <span class="block whitespace-nowrap text-xs text-slate-400">{{ $ticket->location?->code }}</span>
                </td>
                <td class="px-4 py-3"><x-priority-pill :priority="$ticket->priority" /></td>
                <td class="px-4 py-3"><x-status-badge :status="$ticket->status" /></td>
                <td class="px-4 py-3 text-sm text-slate-600">
                    {{ $ticket->assignee?->name ?? '—' }}
                </td>
                <td class="px-4 py-3 text-sm">
                    @if ($ticket->dueInWords())
                        {{-- Past due is the one thing in this table that has to
                             be visible without reading. --}}
                        <span class="{{ $ticket->isBreaching() ? 'font-medium text-red-700' : 'text-slate-600' }}">
                            {{ $ticket->dueInWords() }}
                        </span>
                    @else
                        <span class="text-slate-400">—</span>
                    @endif
                </td>
                <td class="px-4 py-3 text-right">
                    <a href="{{ route('engineer.tickets.show', $ticket) }}" class="text-sm text-brand-700 hover:underline">Open</a>
                </td>
            </tr>
        @endforeach

        <x-slot:empty>
            <x-empty-state title="Nothing in the queue"
                           message="Tickets raised by assistants during lab rounds appear here, along with any raised automatically when an access point drops."
                           icon="ticket" />
        </x-slot:empty>

        <x-slot:pagination><x-pagination :paginator="$tickets" /></x-slot:pagination>
    </x-data-table>
</x-app-layout>
