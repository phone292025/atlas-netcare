<x-app-layout>
    <x-slot name="title">My tickets</x-slot>

    @php
        $view = match (true) {
            request()->boolean('outstanding') && ! request()->filled('status') => 'open',
            request('status') === 'resolved' => 'resolved',
            request('status') === 'closed' => 'closed',
            request()->filled('status') => null,
            default => 'all',
        };
        $to = fn (array $query = []) => route('assistant.tickets.index', $query);
        $tabs = [
            ['label' => 'All', 'url' => $to(), 'count' => $counts['all'], 'active' => $view === 'all'],
            ['label' => 'Still open', 'url' => $to(['outstanding' => 1]), 'count' => $counts['open'], 'active' => $view === 'open'],
            ['label' => 'Resolved', 'url' => $to(['status' => 'resolved']), 'count' => $counts['resolved'], 'active' => $view === 'resolved'],
            ['label' => 'Closed', 'url' => $to(['status' => 'closed']), 'count' => $counts['closed'], 'active' => $view === 'closed'],
        ];
    @endphp

    <x-page-header title="My tickets"
                   subtitle="Faults you have reported, and where each one has got to.">
        <x-slot:actions>
            <x-button :href="route('assistant.tickets.create')" icon="plus">Raise a ticket</x-button>
        </x-slot:actions>
    </x-page-header>

    <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <x-tab-nav :tabs="$tabs" label="Ticket views" class="sm:flex-1" />

        {{-- The finer status filter, for "which of mine are in progress". --}}
        <form method="GET" class="flex items-center gap-2 sm:pb-1.5">
            @if (request()->boolean('outstanding'))
                <input type="hidden" name="outstanding" value="1">
            @endif
            <select name="status" aria-label="Status" onchange="this.form.requestSubmit()" class="h-9 w-auto py-0 pl-3 pr-8 text-sm">
                <option value="">Any status</option>
                @foreach ($statuses as $value => $label)
                    <option value="{{ $value }}" @selected(request('status') === $value)>{{ $label }}</option>
                @endforeach
            </select>
            <noscript><x-button type="submit" variant="secondary" size="sm">Filter</x-button></noscript>
        </form>
    </div>

    @if ($tickets->isEmpty())
        <div class="rounded-xl border border-line bg-surface">
            @if ($counts['all'] > 0)
                <x-empty-state title="Nothing in this view" icon="funnel"
                               message="None of your tickets match. Try another view.">
                    <x-slot:action>
                        <x-button variant="secondary" :href="route('assistant.tickets.index')">Show all</x-button>
                    </x-slot:action>
                </x-empty-state>
            @else
                <x-empty-state title="You have not reported anything yet" icon="ticket"
                               message="Raise a ticket when you find a fault, or straight from a failed item on an inspection.">
                    <x-slot:action>
                        <x-button :href="route('assistant.tickets.create')" icon="plus">Raise a ticket</x-button>
                    </x-slot:action>
                </x-empty-state>
            @endif
        </div>
    @endif

    {{-- ON A PHONE, A LIST OF CARDS. The assistant is the mobile-first role and
         this is one of their four screens, but a six-column table at 375px
         shows one column and hides the rest behind a sideways scroll nobody
         can see. Below md the same tickets render as cards; the table is for
         a desk. Same query, same pagination. --}}
    <ul id="mobile-ticket-list" class="space-y-2 md:hidden">
        @foreach ($tickets as $ticket)
            <li>
                <a href="{{ route('assistant.tickets.show', $ticket) }}"
                   class="block rounded-xl border border-line bg-surface p-4 shadow-xs active:bg-sunken">
                    <span class="flex items-center justify-between gap-2">
                        <span class="font-mono text-xs text-fg-muted">{{ $ticket->ticket_number }}</span>
                        <x-status-badge :status="$ticket->status" />
                    </span>
                    <span class="mt-1 block font-medium text-fg">{{ $ticket->title }}</span>
                    <span class="mt-1 block text-xs text-fg-muted">{{ $ticket->subjectLabel() }} · {{ $ticket->opened_at?->diffForHumans() }}</span>
                    <span class="mt-3 flex items-center justify-between gap-2 text-xs">
                        <x-priority-pill :priority="$ticket->priority" />
                        <span class="flex items-center gap-1.5 {{ $ticket->assignee ? 'text-fg-muted' : 'text-fg-subtle' }}">
                            <x-avatar :name="$ticket->assignee?->name" />
                            {{ $ticket->assignee?->name ?? 'Not picked up yet' }}
                        </span>
                    </span>
                </a>
            </li>
        @endforeach
    </ul>

    @if ($tickets->isNotEmpty())
        <div class="overflow-hidden rounded-xl border border-line bg-surface shadow-xs max-md:hidden">
            <table class="min-w-full text-sm">
                <thead class="border-b border-line bg-sunken/60 text-left text-xs font-medium text-fg-muted">
                    <tr>
                        <th scope="col" class="h-10 px-4 font-medium">Ticket</th>
                        <th scope="col" class="h-10 px-4 font-medium">Subject</th>
                        <th scope="col" class="h-10 px-4 font-medium">Priority</th>
                        <th scope="col" class="h-10 px-4 font-medium">Status</th>
                        <th scope="col" class="h-10 px-4 font-medium">With</th>
                        <th scope="col" class="h-10 px-4 font-medium">Raised</th>
                        <th scope="col" class="h-10 w-10 px-4"><span class="sr-only">Open</span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    @foreach ($tickets as $ticket)
                        @php $url = route('assistant.tickets.show', $ticket); @endphp
                        <tr class="group cursor-pointer transition-colors hover:bg-sunken/70" onclick="window.location='{{ $url }}'">
                            <td class="max-w-md px-4 py-3">
                                <a href="{{ $url }}" class="block font-mono text-xs text-fg-muted group-hover:text-brand-700">{{ $ticket->ticket_number }}</a>
                                <span class="mt-0.5 block truncate font-medium text-fg">{{ $ticket->title }}</span>
                            </td>
                            <td class="whitespace-nowrap px-4 py-3">
                                <span class="block font-mono text-xs text-fg">{{ $ticket->subjectLabel() }}</span>
                                <span class="block text-xs text-fg-subtle">{{ $ticket->location?->code }}</span>
                            </td>
                            <td class="px-4 py-3"><x-priority-pill :priority="$ticket->priority" /></td>
                            <td class="px-4 py-3"><x-status-badge :status="$ticket->status" /></td>
                            <td class="px-4 py-3">
                                <span class="flex items-center gap-2">
                                    <x-avatar :name="$ticket->assignee?->name" />
                                    <span class="truncate {{ $ticket->assignee ? 'text-fg' : 'text-fg-subtle' }}">{{ $ticket->assignee?->name ?? 'Not picked up yet' }}</span>
                                </span>
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 text-fg-muted">
                                <time datetime="{{ $ticket->opened_at?->toIso8601String() }}">{{ $ticket->opened_at?->diffForHumans() }}</time>
                            </td>
                            <td class="px-4 py-3 text-right">
                                <x-icon name="chevron-right" class="size-4 text-fg-subtle transition group-hover:translate-x-0.5 group-hover:text-fg" />
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="mt-4">
            <x-pagination :paginator="$tickets" />
        </div>
    @endif
</x-app-layout>
