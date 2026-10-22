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
            <table class="w-full table-fixed text-sm">
                @include('engineer.tickets._cols')
                <thead class="border-b border-line bg-sunken/60 text-left text-xs font-medium text-fg-muted">
                    <tr>
                        <th scope="col" class="h-10 px-4 font-medium">Ticket</th>
                        <th scope="col" class="h-10 px-4 font-medium">Priority</th>
                        <th scope="col" class="h-10 px-4 font-medium">Status</th>
                        <th scope="col" class="h-10 px-4 font-medium">Who has it</th>
                        <th scope="col" class="h-10 px-4 font-medium">Against its target</th>
                        <th scope="col" class="h-10 px-4"><span class="sr-only">Open</span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    @foreach ($tickets as $ticket)
                        @include('engineer.tickets._row')
                    @endforeach
                </tbody>
            </table>
        </div>

        {{-- Phone: one card per ticket, the same facts. --}}
        <ul id="mobile-ticket-list" class="space-y-2 md:hidden">
            @foreach ($tickets as $ticket)
                @include('engineer.tickets._card')
            @endforeach
        </ul>

        <div class="mt-4">
            <x-pagination :paginator="$tickets" />
        </div>
    @endif

    {{-- Only on the whole open queue: what got done lately, and whether it made
         its target, without switching tabs. --}}
    @if ($recentlyFinished->isNotEmpty())
        <section class="mt-10" aria-labelledby="finished-heading">
            <div class="mb-3 flex items-baseline justify-between gap-3">
                <h2 id="finished-heading" class="text-base font-semibold text-fg">Finished this week</h2>
                <a href="{{ $to(['status' => 'closed']) }}" class="link text-sm">All closed tickets</a>
            </div>

            <div class="overflow-hidden rounded-xl border border-line bg-surface shadow-xs max-md:hidden">
                <table class="w-full table-fixed text-sm">
                    @include('engineer.tickets._cols')
                    <thead class="sr-only">
                        <tr>
                            <th scope="col">Ticket</th>
                            <th scope="col">Priority</th>
                            <th scope="col">Status</th>
                            <th scope="col">Who had it</th>
                            <th scope="col">Against its target</th>
                            <th scope="col">Open</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        @foreach ($recentlyFinished as $ticket)
                            @include('engineer.tickets._row')
                        @endforeach
                    </tbody>
                </table>
            </div>

            <ul class="space-y-2 md:hidden">
                @foreach ($recentlyFinished as $ticket)
                    @include('engineer.tickets._card')
                @endforeach
            </ul>
        </section>
    @endif
</x-app-layout>
