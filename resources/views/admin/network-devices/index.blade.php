<x-app-layout>
    <x-slot name="title">Network devices</x-slot>

    @php($narrowed = request()->hasAny(['search', 'kind', 'location', 'removed']))

    <x-page-header title="Network devices"
                   subtitle="Switches, routers and servers. Access points are connected to these, and have their own list.">
        <x-slot:actions>
            <x-button icon="plus" :href="route('admin.devices.create')">Add device</x-button>
        </x-slot:actions>
    </x-page-header>

    {{-- Selects submit themselves; search submits on Enter. --}}
    <form method="GET" class="mb-4 flex flex-wrap items-center gap-2">
        @foreach (array_filter(request()->only(['sort', 'direction']), 'filled') as $key => $value)
            <input type="hidden" name="{{ $key }}" value="{{ $value }}">
        @endforeach

        <x-search-input placeholder="Code, name or IP" />

        <select name="kind" aria-label="Kind" onchange="this.form.requestSubmit()" class="h-9 w-auto py-0 pl-3 pr-8 text-sm">
            <option value="">Any kind</option>
            @foreach ($kinds as $value => $label)
                <option value="{{ $value }}" @selected(request('kind') === $value)>{{ $label }}</option>
            @endforeach
        </select>

        <select name="location" aria-label="Location" onchange="this.form.requestSubmit()" class="h-9 w-auto py-0 pl-3 pr-8 text-sm">
            <option value="">Anywhere</option>
            @foreach ($locations as $value => $label)
                <option value="{{ $value }}" @selected((string) request('location') === (string) $value)>{{ $label }}</option>
            @endforeach
        </select>

        <label class="flex h-9 items-center gap-2 rounded-md px-2 text-sm text-fg-muted hover:text-fg">
            <input type="checkbox" name="removed" value="1" @checked(request()->boolean('removed')) onchange="this.form.requestSubmit()">
            Include retired
        </label>

        <noscript><x-button type="submit" variant="secondary" size="sm">Filter</x-button></noscript>

        @if ($narrowed)
            <x-button variant="ghost" size="sm" icon="close" :href="route('admin.devices.index')">Clear filters</x-button>
        @endif
    </form>

    <x-data-table :headers="[
        ['label' => 'Device', 'sort' => 'code'],
        ['label' => 'Kind', 'sort' => 'kind'],
        'Location',
        'Connected to',
        ['label' => 'Below it', 'align' => 'right'],
        ['label' => 'Status', 'sort' => 'status'],
        ['label' => 'Last seen', 'sort' => 'last_seen_at'],
        '',
    ]">
        @foreach ($devices as $device)
            @php($url = route('admin.devices.show', $device))
            <tr @class(['group cursor-pointer transition-colors hover:bg-sunken/70', 'opacity-60' => $device->trashed()])
                onclick="window.location='{{ $url }}'">
                <td class="px-4 py-3">
                    <span class="flex items-center gap-3">
                        <span class="grid size-8 flex-none place-items-center rounded-lg bg-sunken text-fg-muted">
                            <x-icon :name="$device->kind->icon()" class="size-4" />
                        </span>
                        <span>
                            <a href="{{ $url }}" class="font-mono font-medium text-fg group-hover:text-brand-700">{{ $device->code }}</a>
                            @if ($device->trashed())
                                <x-badge class="ml-1">Retired</x-badge>
                            @endif
                            @if ($device->name || $device->model)
                                <span class="block text-xs text-fg-subtle">{{ $device->name ?? $device->model }}</span>
                            @endif
                        </span>
                    </span>
                </td>
                <td class="px-4 py-3 text-fg-muted">{{ $device->kind->label() }}</td>
                <td class="px-4 py-3 text-fg-muted">{{ $device->location?->code }}</td>
                <td class="px-4 py-3">
                    @if ($device->parent)
                        <span class="font-mono text-xs text-fg-muted">{{ $device->parent->code }}</span>
                    @else
                        <span class="text-xs text-fg-subtle">Nothing</span>
                    @endif
                </td>
                <td class="px-4 py-3 text-right tabular-nums text-fg-muted">{{ $device->children_count }}</td>
                <td class="px-4 py-3"><x-status-badge :status="$device->status" /></td>
                <td class="whitespace-nowrap px-4 py-3 text-fg-muted">
                    @if ($device->last_seen_at)
                        <time datetime="{{ $device->last_seen_at->toIso8601String() }}"
                              title="{{ $device->last_seen_at->campus()->format('D j M Y, H:i') }}">{{ $device->last_seen_at->diffForHumans() }}</time>
                    @else
                        {{-- Nothing reports on these until an agent watches them
                             (roadmap task 1.2). --}}
                        <span class="text-xs text-fg-subtle">Never heard from</span>
                    @endif
                </td>
                <td class="px-4 py-3 text-right">
                    <a href="{{ route('admin.devices.edit', $device) }}" onclick="event.stopPropagation()"
                       class="btn btn-ghost btn-sm opacity-0 transition group-hover:opacity-100 focus:opacity-100">
                        <x-icon name="edit" class="size-3.5" /> Edit
                    </a>
                </td>
            </tr>
        @endforeach

        <x-slot:empty>
            @if ($narrowed)
                <x-empty-state title="No devices match" icon="funnel"
                               message="Nothing in the inventory matches those filters.">
                    <x-slot:action>
                        <x-button variant="secondary" :href="route('admin.devices.index')">Clear filters</x-button>
                    </x-slot:action>
                </x-empty-state>
            @else
                <x-empty-state title="No network devices yet" icon="network"
                               message="Add the switches and routers your access points plug into. When one of them fails, its access points fail with it.">
                    <x-slot:action>
                        <x-button icon="plus" :href="route('admin.devices.create')">Add the first device</x-button>
                    </x-slot:action>
                </x-empty-state>
            @endif
        </x-slot:empty>

        <x-slot:pagination><x-pagination :paginator="$devices" /></x-slot:pagination>
    </x-data-table>
</x-app-layout>
