<x-app-layout>
    <x-slot name="title">Access points</x-slot>

    <x-page-header title="Access point inventory"
                   subtitle="{{ $accessPoints->total() }} {{ Str::plural('access point', $accessPoints->total()) }} on record.">
        <x-slot:actions>
            <a href="{{ route('admin.aps.create') }}"
               class="inline-flex items-center gap-1.5 rounded-md bg-brand-600 px-3 py-2 text-sm font-medium text-white hover:bg-brand-700">
                <x-icon name="plus" class="h-4 w-4" /> Add access point
            </a>
        </x-slot:actions>
    </x-page-header>

    <x-data-table :headers="[
        ['label' => 'AP code', 'sort' => 'ap_code'],
        'Location',
        'MAC / IP',
        ['label' => 'Status', 'sort' => 'status'],
        ['label' => 'Capacity', 'sort' => 'client_capacity', 'align' => 'right'],
        ['label' => 'Last seen', 'sort' => 'last_seen_at'],
        '',
    ]">
        <x-slot:filters>
            <form method="GET" class="flex flex-wrap items-end gap-2">
                <div class="w-48">
                    <x-form.input name="search" label="Search" :value="request('search')" placeholder="Code, MAC or IP" />
                </div>
                <div class="w-52">
                    <x-form.select name="location" label="Location" blank="Anywhere" :options="$locations" :selected="request('location')" />
                </div>
                <div class="w-44">
                    <x-form.select name="status" label="Status" blank="Any status" :options="$statuses" :selected="request('status')" />
                </div>
                <label class="flex items-center gap-2 py-2 text-sm text-slate-600">
                    <input type="checkbox" name="removed" value="1" @checked(request()->boolean('removed'))
                           class="rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                    Include removed
                </label>
                <button type="submit" class="rounded-md bg-white px-3 py-2 text-sm font-medium text-slate-700 ring-1 ring-slate-300 hover:bg-slate-50">Filter</button>
                @if (request()->hasAny(['search', 'location', 'status', 'removed']))
                    <a href="{{ route('admin.aps.index') }}" class="px-2 py-2 text-sm text-slate-500 underline">Clear</a>
                @endif
            </form>
        </x-slot:filters>

        @foreach ($accessPoints as $ap)
            <tr class="hover:bg-slate-50 {{ $ap->trashed() ? 'opacity-60' : '' }}">
                <td class="px-4 py-3">
                    <a href="{{ route('admin.aps.show', $ap) }}" class="font-medium text-slate-900 hover:text-brand-700">{{ $ap->ap_code }}</a>
                    @if ($ap->trashed())
                        <span class="ml-1 text-xs text-slate-400">removed</span>
                    @endif
                    @if ($ap->model)
                        <span class="block text-xs text-slate-400">{{ $ap->model }}</span>
                    @endif
                </td>
                <td class="px-4 py-3 text-slate-600">{{ $ap->location?->code }}</td>
                <td class="px-4 py-3">
                    {{-- Tabular figures so MACs and IPs line up column-wise and a
                         transposed pair of digits is visible at a glance. --}}
                    <span class="block font-mono text-xs tabular-nums text-slate-600">{{ $ap->mac_address }}</span>
                    <span class="block font-mono text-xs tabular-nums text-slate-400">{{ $ap->ip_address ?? '—' }}</span>
                </td>
                <td class="px-4 py-3"><x-status-badge :status="$ap->status" /></td>
                <td class="px-4 py-3 text-right tabular-nums text-slate-600">{{ $ap->client_capacity }}</td>
                <td class="px-4 py-3 text-slate-600">
                    @if ($ap->last_seen_at)
                        <time datetime="{{ $ap->last_seen_at->toIso8601String() }}"
                              title="{{ $ap->last_seen_at->format('D j M Y, H:i') }}">{{ $ap->last_seen_at->diffForHumans() }}</time>
                    @else
                        {{-- The collector writes last_seen_at. Until M5 runs, no
                             access point has ever been polled. --}}
                        <span class="text-xs text-slate-400">Never polled</span>
                    @endif
                </td>
                <td class="px-4 py-3 text-right">
                    <a href="{{ route('admin.aps.edit', $ap) }}" class="text-sm text-brand-700 hover:underline">Edit</a>
                </td>
            </tr>
        @endforeach

        <x-slot:empty>
            <x-empty-state title="No access points match those filters"
                           message="Access points are registered here. M5 polls them and fills in the live columns."
                           icon="wifi">
                <x-slot:action>
                    <a href="{{ route('admin.aps.create') }}" class="rounded-md bg-brand-600 px-3 py-2 text-sm font-medium text-white hover:bg-brand-700">Add the first access point</a>
                </x-slot:action>
            </x-empty-state>
        </x-slot:empty>

        <x-slot:pagination><x-pagination :paginator="$accessPoints" /></x-slot:pagination>
    </x-data-table>
</x-app-layout>
