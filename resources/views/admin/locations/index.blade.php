<x-app-layout>
    <x-slot name="title">Locations</x-slot>

    <x-page-header title="Locations"
                   subtitle="Campus places that hold lab PCs and access points. Seeded with the system — every asset belongs to one.">
        <x-slot:actions>
            <a href="{{ route('admin.locations.create') }}"
               class="inline-flex items-center gap-1.5 rounded-md bg-brand-600 px-3 py-2 text-sm font-medium text-white hover:bg-brand-700">
                <x-icon name="plus" class="h-4 w-4" /> Add location
            </a>
        </x-slot:actions>
    </x-page-header>

    <x-data-table :headers="['Code', 'Name', 'Type', 'Floor', 'Assets', '']">
        <x-slot:filters>
            <form method="GET" class="flex flex-wrap items-end gap-2">
                <div class="w-56">
                    <x-form.input name="search" label="Search" :value="request('search')" placeholder="Code or name" />
                </div>
                <div class="w-40">
                    <x-form.select name="type" label="Type" blank="Any type" :options="$types" :selected="request('type')" />
                </div>
                <button type="submit" class="rounded-md bg-white px-3 py-2 text-sm font-medium text-slate-700 ring-1 ring-slate-300 hover:bg-slate-50">Filter</button>
                @if (request()->hasAny(['search', 'type']))
                    <a href="{{ route('admin.locations.index') }}" class="px-2 py-2 text-sm text-slate-500 underline">Clear</a>
                @endif
            </form>
        </x-slot:filters>

        @foreach ($locations as $location)
            <tr class="hover:bg-slate-50">
                <td class="px-4 py-3 font-medium text-slate-900">{{ $location->code }}</td>
                <td class="px-4 py-3 text-slate-600">{{ $location->name }}</td>
                <td class="px-4 py-3"><x-badge tone="neutral">{{ $types[$location->type] ?? $location->type }}</x-badge></td>
                <td class="px-4 py-3 text-slate-600">{{ $location->floor ?? '—' }}</td>
                <td class="px-4 py-3 text-slate-600 tabular-nums">
                    {{ $location->lab_pcs_count }} PC{{ $location->lab_pcs_count === 1 ? '' : 's' }},
                    {{ $location->access_points_count }} AP{{ $location->access_points_count === 1 ? '' : 's' }}
                </td>
                <td class="px-4 py-3 text-right">
                    <a href="{{ route('admin.locations.edit', $location) }}" class="text-sm text-brand-700 hover:underline">Edit</a>
                </td>
            </tr>
        @endforeach

        <x-slot:empty>
            <x-empty-state title="No locations match those filters"
                           message="Every lab PC and access point has to belong to a location."
                           icon="building">
                <x-slot:action>
                    <a href="{{ route('admin.locations.create') }}" class="rounded-md bg-brand-600 px-3 py-2 text-sm font-medium text-white hover:bg-brand-700">Add a location</a>
                </x-slot:action>
            </x-empty-state>
        </x-slot:empty>

        <x-slot:pagination><x-pagination :paginator="$locations" /></x-slot:pagination>
    </x-data-table>
</x-app-layout>
