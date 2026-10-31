<x-app-layout>
    <x-slot name="title">Lab PCs</x-slot>

    @php($narrowed = request()->hasAny(['search', 'location', 'status', 'retired']))

    <x-page-header title="Lab PC inventory"
                   subtitle="{{ $labPcs->total() }} {{ Str::plural('machine', $labPcs->total()) }}. Retired machines are hidden unless you ask for them.">
        <x-slot:actions>
            <x-button icon="plus" :href="route('admin.lab-pcs.create')">Add machine</x-button>
        </x-slot:actions>
    </x-page-header>

    {{-- Selects submit themselves; search submits on Enter. --}}
    <form method="GET" class="mb-4 flex flex-wrap items-center gap-2">
        @foreach (array_filter(request()->only(['sort', 'direction']), 'filled') as $key => $value)
            <input type="hidden" name="{{ $key }}" value="{{ $value }}">
        @endforeach

        <x-search-input placeholder="Asset tag or hostname" />

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
            <input type="checkbox" name="retired" value="1" @checked(request()->boolean('retired')) onchange="this.form.requestSubmit()">
            Include retired
        </label>

        <noscript><x-button type="submit" variant="secondary" size="sm">Filter</x-button></noscript>

        @if ($narrowed)
            <x-button variant="ghost" size="sm" icon="close" :href="route('admin.lab-pcs.index')">Clear filters</x-button>
        @endif
    </form>

    <x-data-table :headers="[
        ['label' => 'Asset tag', 'sort' => 'asset_tag'],
        'Location',
        ['label' => 'Hostname', 'sort' => 'hostname'],
        ['label' => 'Status', 'sort' => 'status'],
        'Risk',
        '',
    ]">
        @foreach ($labPcs as $pc)
            @php($url = route('admin.lab-pcs.show', $pc))
            <tr @class(['group cursor-pointer transition-colors hover:bg-sunken/70', 'opacity-60' => $pc->trashed()])
                onclick="window.location='{{ $url }}'">
                <td class="px-4 py-3">
                    <span class="flex items-center gap-3">
                        <span class="grid size-8 flex-none place-items-center rounded-lg bg-sunken text-fg-muted">
                            <x-icon name="desktop" class="size-4" />
                        </span>
                        <span>
                            <a href="{{ $url }}" class="font-mono font-medium text-fg group-hover:text-brand-700">{{ $pc->asset_tag }}</a>
                            @if ($pc->trashed())
                                <x-badge class="ml-1">Retired</x-badge>
                            @endif
                        </span>
                    </span>
                </td>
                <td class="px-4 py-3 text-fg-muted">{{ $pc->location?->code }}</td>
                <td class="px-4 py-3 font-mono text-[13px] text-fg-muted">{{ $pc->hostname ?? '-' }}</td>
                <td class="px-4 py-3"><x-status-badge :status="$pc->status" /></td>
                <td class="px-4 py-3">
                    @if ($pc->risk_band)
                        <span class="flex items-center gap-2">
                            <x-status-badge :status="$pc->risk_band" />
                            <span class="text-xs tabular-nums text-fg-subtle">{{ round((float) $pc->risk_score) }}</span>
                        </span>
                    @else
                        {{-- Saying so beats an empty cell. --}}
                        <span class="text-xs text-fg-subtle">Not scored yet</span>
                    @endif
                </td>
                <td class="px-4 py-3 text-right">
                    {{-- A retired one can't be edited, only restored from its page. --}}
                    @unless ($pc->trashed())
                        <a href="{{ route('admin.lab-pcs.edit', $pc) }}" onclick="event.stopPropagation()"
                           class="btn btn-ghost btn-sm opacity-0 transition group-hover:opacity-100 focus:opacity-100">
                            <x-icon name="edit" class="size-3.5" /> Edit
                        </a>
                    @endunless
                </td>
            </tr>
        @endforeach

        <x-slot:empty>
            @if ($narrowed)
                <x-empty-state title="No machines match" icon="funnel"
                               message="Nothing in the inventory matches those filters.">
                    <x-slot:action>
                        <x-button variant="secondary" :href="route('admin.lab-pcs.index')">Clear filters</x-button>
                    </x-slot:action>
                </x-empty-state>
            @else
                <x-empty-state title="No machines yet" icon="desktop"
                               message="Lab PCs are added here and inspected by assistants during their rounds.">
                    <x-slot:action>
                        <x-button icon="plus" :href="route('admin.lab-pcs.create')">Add the first machine</x-button>
                    </x-slot:action>
                </x-empty-state>
            @endif
        </x-slot:empty>

        <x-slot:pagination><x-pagination :paginator="$labPcs" /></x-slot:pagination>
    </x-data-table>
</x-app-layout>
