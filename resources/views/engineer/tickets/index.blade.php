<x-app-layout>
    <x-slot name="title">Ticket queue</x-slot>

    @php
        $view = match (true) {
            request()->boolean('breaching') => 'breaching',
            request()->boolean('mine') => 'mine',
            request()->boolean('unassigned') => 'unassigned',
            request('status') === 'resolved' => 'resolved',
            request('status') === 'closed' => 'closed',
            request()->filled('status') || request()->filled('search') => null,
            default => 'open',
        };
        $to = fn (array $query = []) => route('engineer.tickets.index', $query);
        $tabs = [
            ['label' => 'Open', 'url' => $to(), 'count' => $counts['open'], 'active' => $view === 'open'],
            ['label' => 'Mine', 'url' => $to(['mine' => 1]), 'count' => $counts['mine'], 'active' => $view === 'mine'],
            ['label' => 'Unassigned', 'url' => $to(['unassigned' => 1]), 'count' => $counts['unassigned'], 'active' => $view === 'unassigned'],
            ['label' => 'Past due', 'url' => $to(['breaching' => 1]), 'count' => $breachCount, 'active' => $view === 'breaching', 'tone' => 'urgent'],
            ['label' => 'Resolved', 'url' => $to(['status' => 'resolved']), 'count' => $counts['resolved'], 'active' => $view === 'resolved'],
            ['label' => 'Closed', 'url' => $to(['status' => 'closed']), 'count' => $counts['closed'], 'active' => $view === 'closed'],
        ];
        // The tab's own filter rides along when the toolbar is used.
        $keep = array_filter(request()->only(['mine', 'unassigned', 'breaching', 'status']), 'filled');
        $narrowed = collect(request()->only(['search', 'priority', 'location', 'assignee']))->filter()->isNotEmpty();
    @endphp

    <x-page-header title="Ticket queue"
                   :subtitle="$counts['open'].' open'.($breachCount > 0 ? ', '.$breachCount.' past due' : '').'. Most urgent first, then whatever is due soonest.'">
        <x-slot:actions>
            <x-button :href="route('assistant.tickets.create')" icon="plus">Raise ticket</x-button>
        </x-slot:actions>
    </x-page-header>

    <x-tab-nav :tabs="$tabs" label="Ticket views" class="mb-4" />

    {{-- Filters submit themselves on change; search on Enter. --}}
    <form method="GET" class="mb-4 flex flex-wrap items-center gap-2">
        @foreach ($keep as $key => $value)
            <input type="hidden" name="{{ $key }}" value="{{ $value }}">
        @endforeach

        <label class="relative min-w-[14rem] flex-1 sm:max-w-xs">
            <span class="sr-only">Search tickets</span>
            <x-icon name="search" class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-fg-subtle" />
            <input type="search" name="search" value="{{ request('search') }}" placeholder="Search number or title"
                   class="h-9 w-full pl-9 text-sm">
        </label>

        <select name="priority" aria-label="Priority" onchange="this.form.requestSubmit()" class="h-9 w-auto py-0 pl-3 pr-8 text-sm">
            <option value="">Any priority</option>
            @foreach ($priorities as $value => $label)
                <option value="{{ $value }}" @selected(request('priority') === $value)>{{ $label }}</option>
            @endforeach
        </select>

        <select name="location" aria-label="Location" onchange="this.form.requestSubmit()" class="h-9 w-auto py-0 pl-3 pr-8 text-sm">
            <option value="">Any location</option>
            @foreach ($locations as $value => $label)
                <option value="{{ $value }}" @selected((string) request('location') === (string) $value)>{{ $label }}</option>
            @endforeach
        </select>

        @unless ($view === 'mine' || $view === 'unassigned')
            <select name="assignee" aria-label="Engineer" onchange="this.form.requestSubmit()" class="h-9 w-auto py-0 pl-3 pr-8 text-sm">
                <option value="">Anyone</option>
                @foreach ($engineers as $value => $label)
                    <option value="{{ $value }}" @selected((string) request('assignee') === (string) $value)>{{ $label }}</option>
                @endforeach
            </select>
        @endunless

        @if ($narrowed)
            <a href="{{ route('engineer.tickets.index', $keep) }}" class="btn btn-ghost btn-sm">
                <x-icon name="close" class="size-3.5" />
                Clear filters
            </a>
        @endif
    </form>

    @if ($tickets->isEmpty())
        <div class="rounded-xl border border-line bg-surface">
            @if ($narrowed)
                <x-empty-state title="No tickets match" icon="funnel"
                               message="Nothing in this view matches those filters.">
                    <x-slot:action>
                        <x-button variant="secondary" :href="route('engineer.tickets.index', $keep)">Clear filters</x-button>
                    </x-slot:action>
                </x-empty-state>
            @else
                <x-empty-state title="Nothing in the queue" icon="ticket"
                               message="Tickets raised by assistants during lab rounds appear here, along with any raised automatically when an access point drops." />
            @endif
        </div>
    @else
        {{-- Desktop: a table, each row a way into the ticket. --}}
        <div class="overflow-hidden rounded-xl border border-line bg-surface shadow-xs max-md:hidden">
            <table class="min-w-full text-sm">
                <thead class="border-b border-line bg-sunken/60 text-left text-xs font-medium text-fg-muted">
                    <tr>
                        <th scope="col" class="h-10 px-4 font-medium">Ticket</th>
                        <th scope="col" class="h-10 px-4 font-medium">Asset</th>
                        <th scope="col" class="h-10 px-4 font-medium">Priority</th>
                        <th scope="col" class="h-10 px-4 font-medium">Status</th>
                        <th scope="col" class="h-10 px-4 font-medium">Assignee</th>
                        <th scope="col" class="h-10 px-4 font-medium">Due</th>
                        <th scope="col" class="h-10 w-10 px-4"><span class="sr-only">Open</span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    @foreach ($tickets as $ticket)
                        @php $url = route('engineer.tickets.show', $ticket); @endphp
                        <tr class="group cursor-pointer transition-colors hover:bg-sunken/70" onclick="window.location='{{ $url }}'">
                            <td class="max-w-md px-4 py-3">
                                <a href="{{ $url }}" class="block font-mono text-xs text-fg-muted group-hover:text-brand-700">{{ $ticket->ticket_number }}</a>
                                <span class="mt-0.5 block truncate font-medium text-fg">{{ $ticket->title }}</span>
                            </td>
                            <td class="whitespace-nowrap px-4 py-3">
                                {{-- nowrap: an asset tag is one token, unreadable broken at its hyphen. --}}
                                <span class="block font-mono text-xs text-fg">{{ $ticket->subjectLabel() }}</span>
                                <span class="block text-xs text-fg-subtle">{{ $ticket->location?->code }}</span>
                            </td>
                            <td class="px-4 py-3"><x-priority-pill :priority="$ticket->priority" /></td>
                            <td class="px-4 py-3"><x-status-badge :status="$ticket->status" /></td>
                            <td class="px-4 py-3">
                                <span class="flex items-center gap-2">
                                    <x-avatar :name="$ticket->assignee?->name" />
                                    <span class="truncate {{ $ticket->assignee ? 'text-fg' : 'text-fg-subtle' }}">{{ $ticket->assignee?->name ?? 'Nobody yet' }}</span>
                                </span>
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 text-sm"><x-ticket-due :ticket="$ticket" /></td>
                            <td class="px-4 py-3 text-right">
                                <x-icon name="chevron-right" class="size-4 text-fg-subtle transition group-hover:translate-x-0.5 group-hover:text-fg" />
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        {{-- Phone: one card per ticket, the same facts. --}}
        <ul id="mobile-ticket-list" class="space-y-2 md:hidden">
            @foreach ($tickets as $ticket)
                <li>
                    <a href="{{ route('engineer.tickets.show', $ticket) }}"
                       class="block rounded-xl border border-line bg-surface p-4 shadow-xs active:bg-sunken">
                        <span class="flex items-center justify-between gap-2">
                            <span class="font-mono text-xs text-fg-muted">{{ $ticket->ticket_number }}</span>
                            <x-priority-pill :priority="$ticket->priority" />
                        </span>
                        <span class="mt-1 block font-medium text-fg">{{ $ticket->title }}</span>
                        <span class="mt-1 block text-xs text-fg-muted">{{ $ticket->subjectLabel() }} &middot; {{ $ticket->location?->code }}</span>
                        <span class="mt-3 flex items-center justify-between gap-2">
                            <x-status-badge :status="$ticket->status" />
                            <x-ticket-due :ticket="$ticket" class="text-xs" />
                        </span>
                    </a>
                </li>
            @endforeach
        </ul>

        <div class="mt-4">
            <x-pagination :paginator="$tickets" />
        </div>
    @endif
</x-app-layout>
