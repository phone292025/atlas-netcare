@props(['headers' => []])

{{--
    The table every index screen uses. Build one shape, use it seven times.

    Rows go in the default slot as plain <tr> elements, so a module owner keeps
    full control of each cell:

        <x-data-table :headers="[
            'Ticket',
            ['label' => 'Priority', 'sort' => 'priority'],
            ['label' => 'Latency', 'sort' => 'latency_ms', 'align' => 'right'],
        ]">
            <x-slot:filters> ...filter controls... </x-slot:filters>

            @foreach ($tickets as $ticket)
                <tr class="hover:bg-slate-50">
                    <td class="px-4 py-3">{{ $ticket->reference }}</td>
                    <td class="px-4 py-3"><x-priority-pill :priority="$ticket->priority" /></td>
                    <td class="px-4 py-3 text-right tabular-nums">{{ $ticket->latency_ms }}</td>
                </tr>
            @endforeach

            <x-slot:empty>
                <x-empty-state title="No tickets match these filters" icon="ticket" />
            </x-slot:empty>

            <x-slot:pagination>
                <x-pagination :paginator="$tickets" />
            </x-slot:pagination>
        </x-data-table>

    THIS COMPONENT NEVER QUERIES. It receives rows already rendered and headers
    as an array. It cannot tell whether the numbers in it came from a simulator
    or a live access point, and that is exactly the property that lets the data
    source change without anyone touching the frontend.

    SORTING is a link, not JavaScript — the controller does the ordering, so the
    sort survives a refresh, a back button and a shared URL. A header with a
    `sort` key renders a link that toggles direction and resets to page 1;
    forgetting that reset lands you on page 7 of a re-sorted list. Reading
    request()->query() here is UI state, not data access.

    EMPTY: the rows slot is checked with trim(), not ComponentSlot::isEmpty(),
    because an @foreach over an empty collection still emits the whitespace
    between its tags — the slot is blank on screen but not an empty string.

    RESPONSIVE: the horizontal scroll is on this component's own container, so a
    wide engineer table never makes the whole page scroll sideways. Below the
    md breakpoint an engineer table should degrade to stacked cards rather than
    scroll — that is the page's decision, not this component's.

    @see docs/06-ui-conventions.md — Blade components, Responsive rules
--}}

@php
    $currentSort = request('sort');
    $currentDirection = request('direction') === 'desc' ? 'desc' : 'asc';

    $columns = collect($headers)->map(function ($header) {
        $header = is_array($header) ? $header : ['label' => $header];

        return [
            'label' => $header['label'] ?? '',
            'sort' => $header['sort'] ?? null,
            'align' => match ($header['align'] ?? 'left') {
                'right' => 'text-right',
                'center' => 'text-center',
                default => 'text-left',
            },
            'class' => $header['class'] ?? '',
        ];
    });

    $hasRows = trim($slot->toHtml()) !== '';
@endphp

<div {{ $attributes->merge(['class' => 'bg-white rounded-lg ring-1 ring-slate-200 shadow-sm overflow-hidden']) }}>

    @isset($filters)
        <div class="flex flex-wrap items-center gap-2 px-4 py-3 border-b border-slate-200 bg-slate-50/60">
            {{ $filters }}
        </div>
    @endisset

    @if ($hasRows)
        {{-- overflow-x-auto belongs here, on the table's own container. --}}
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                    <tr>
                        @foreach ($columns as $column)
                            <th scope="col"
                                class="px-4 py-3 font-medium {{ $column['align'] }} {{ $column['class'] }}"
                                @if ($column['sort'] && $currentSort === $column['sort'])
                                    aria-sort="{{ $currentDirection === 'asc' ? 'ascending' : 'descending' }}"
                                @endif>

                                @if ($column['sort'])
                                    @php
                                        $isSorted = $currentSort === $column['sort'];
                                        $nextDirection = $isSorted && $currentDirection === 'asc' ? 'desc' : 'asc';
                                    @endphp

                                    <a href="{{ request()->fullUrlWithQuery([
                                           'sort' => $column['sort'],
                                           'direction' => $nextDirection,
                                           'page' => null,
                                       ]) }}"
                                       class="inline-flex items-center gap-1 hover:text-slate-900
                                              focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 rounded">
                                        {{ $column['label'] }}
                                        @if ($isSorted)
                                            <x-icon :name="$currentDirection === 'asc' ? 'chevron-up' : 'chevron-down'"
                                                    class="w-3.5 h-3.5 text-slate-700" />
                                        @else
                                            <x-icon name="sort" class="w-3.5 h-3.5 text-slate-300" />
                                        @endif
                                    </a>
                                @else
                                    {{ $column['label'] }}
                                @endif
                            </th>
                        @endforeach
                    </tr>
                </thead>

                <tbody class="divide-y divide-slate-100 text-slate-700">
                    {{ $slot }}
                </tbody>
            </table>
        </div>

        @isset($pagination)
            <div class="border-t border-slate-200 px-4 py-3">{{ $pagination }}</div>
        @endisset
    @else
        {{-- No rows. Every index view must supply an empty slot; without one
             this falls back to something honest rather than a bare table
             header sitting above nothing. --}}
        @isset($empty)
            {{ $empty }}
        @else
            <x-empty-state title="Nothing to show yet" />
        @endisset
    @endif
</div>
