<x-app-layout>
    <x-slot name="title">Access points</x-slot>

    @php($narrowed = request()->hasAny(['search', 'location', 'status', 'removed']))

    <x-page-header title="Access point inventory"
                   subtitle="{{ $accessPoints->total() }} {{ Str::plural('access point', $accessPoints->total()) }} on record. Their live status is on the Network monitor.">
        <x-slot:actions>
            <x-button icon="plus" :href="route('admin.aps.create')">Add access point</x-button>
        </x-slot:actions>
    </x-page-header>

    {{-- Selects submit themselves; search submits on Enter. --}}
    <form method="GET" class="mb-4 flex flex-wrap items-center gap-2">
        @foreach (array_filter(request()->only(['sort', 'direction']), 'filled') as $key => $value)
            <input type="hidden" name="{{ $key }}" value="{{ $value }}">
        @endforeach

        <x-search-input placeholder="Code, MAC or IP" />

        <select name="location" aria-label="Location" onchange="this.form.requestSubmit()" class="h-9 w-auto py-0 pl-3 pr-8 text-sm">
            <option value="">Anywhere</option>
            @foreach ($locations as $value => $label)
                <option value="{{ $value }}" @selected((string) request('location') === (string) $value)>{{ $label }}</option>
            @endforeach
        </select>

        <select name="status" aria-label="Status" onchange="this.form.requestSubmit()" class="h-9 w-auto py-0 pl-3 pr-8 text-sm">
            <option value="">Any status</option>
            @foreach ($statuses as $value => $label)
                <option value="{{ $value }}" @selected(request('status') === $value)>{{ $label }}</option>
            @endforeach
        </select>

        <label class="flex h-9 items-center gap-2 rounded-md px-2 text-sm text-fg-muted hover:text-fg">
            <input type="checkbox" name="removed" value="1" @checked(request()->boolean('removed')) onchange="this.form.requestSubmit()">
            Include removed
        </label>

        <noscript><x-button type="submit" variant="secondary" size="sm">Filter</x-button></noscript>

        @if ($narrowed)
            <x-button variant="ghost" size="sm" icon="close" :href="route('admin.aps.index')">Clear filters</x-button>
        @endif
    </form>

    <x-data-table :headers="[
        ['label' => 'Access point', 'sort' => 'ap_code'],
        'Location',
        'MAC and IP',
        ['label' => 'Status', 'sort' => 'status'],
        ['label' => 'Capacity', 'sort' => 'client_capacity', 'align' => 'right'],
        ['label' => 'Last seen', 'sort' => 'last_seen_at'],
        '',
    ]">
        @foreach ($accessPoints as $ap)
            @php($url = route('admin.aps.show', $ap))
            <tr @class(['group cursor-pointer transition-colors hover:bg-sunken/70', 'opacity-60' => $ap->trashed()])
                onclick="window.location='{{ $url }}'">
                <td class="px-4 py-3">
                    <span class="flex items-center gap-3">
                        <span class="grid size-8 flex-none place-items-center rounded-lg bg-sunken text-fg-muted">
                            <x-icon name="wifi" class="size-4" />
                        </span>
                        <span>
                            <a href="{{ $url }}" class="font-mono font-medium text-fg group-hover:text-brand-700">{{ $ap->ap_code }}</a>
                            @if ($ap->trashed())
                                <x-badge class="ml-1">Removed</x-badge>
                            @endif
                            @if ($ap->model)
                                <span class="block text-xs text-fg-subtle">{{ $ap->model }}</span>
                            @endif
                        </span>
                    </span>
                </td>
                <td class="px-4 py-3 text-fg-muted">{{ $ap->location?->code }}</td>
                <td class="px-4 py-3">
                    {{-- Tabular figures so MACs and IPs line up column-wise and a
                         transposed pair of digits is visible at a glance. --}}
                    <span class="block font-mono text-xs tabular-nums text-fg-muted">{{ $ap->mac_address }}</span>
                    <span class="block font-mono text-xs tabular-nums text-fg-subtle">{{ $ap->ip_address ?? '-' }}</span>
                </td>
                <td class="px-4 py-3"><x-status-badge :status="$ap->status" /></td>
                <td class="px-4 py-3 text-right tabular-nums text-fg-muted">{{ $ap->client_capacity }}</td>
                <td class="whitespace-nowrap px-4 py-3 text-fg-muted">
                    @if ($ap->last_seen_at)
                        <time datetime="{{ $ap->last_seen_at->toIso8601String() }}"
                              title="{{ $ap->last_seen_at->campus()->format('D j M Y, H:i') }}">{{ $ap->last_seen_at->diffForHumans() }}</time>
                    @else
                        {{-- The collector writes last_seen_at. Until it runs, no
                             access point has ever been polled. --}}
                        <span class="text-xs text-fg-subtle">Never polled</span>
                    @endif
                </td>
                <td class="px-4 py-3 text-right">
                    <a href="{{ route('admin.aps.edit', $ap) }}" onclick="event.stopPropagation()"
                       class="btn btn-ghost btn-sm opacity-0 transition group-hover:opacity-100 focus:opacity-100">
                        <x-icon name="edit" class="size-3.5" /> Edit
                    </a>
                </td>
            </tr>
        @endforeach

        <x-slot:empty>
            @if ($narrowed)
                <x-empty-state title="No access points match" icon="funnel"
                               message="Nothing in the inventory matches those filters.">
                    <x-slot:action>
                        <x-button variant="secondary" :href="route('admin.aps.index')">Clear filters</x-button>
                    </x-slot:action>
                </x-empty-state>
            @else
                <x-empty-state title="No access points yet" icon="wifi"
                               message="Access points are registered here. The collector polls them and fills in the live columns.">
                    <x-slot:action>
                        <x-button icon="plus" :href="route('admin.aps.create')">Add the first access point</x-button>
                    </x-slot:action>
                </x-empty-state>
            @endif
        </x-slot:empty>

        <x-slot:pagination><x-pagination :paginator="$accessPoints" /></x-slot:pagination>
    </x-data-table>
</x-app-layout>
