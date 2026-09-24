<x-app-layout>
    <x-slot name="title">Locations</x-slot>

    <x-page-header title="Locations"
                   subtitle="Campus places that hold lab PCs and access points. Seeded with the system, and every asset belongs to one.">
        <x-slot:actions>
            <a href="{{ route('admin.locations.create') }}"
               class="btn btn-primary">
                <x-icon name="plus" class="size-4" /> Add location
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
                <button type="submit" class="btn btn-secondary">Filter</button>
                @if (request()->hasAny(['search', 'type']))
                    <a href="{{ route('admin.locations.index') }}" class="btn btn-ghost">Clear</a>
                @endif
            </form>
        </x-slot:filters>

        @foreach ($locations as $location)
            <tr class="hover:bg-sunken/70">
                <td class="px-4 py-3 font-medium text-fg">{{ $location->code }}</td>
                <td class="px-4 py-3 text-fg-muted">{{ $location->name }}</td>
                <td class="px-4 py-3"><x-badge tone="neutral">{{ $types[$location->type] ?? $location->type }}</x-badge></td>
                <td class="px-4 py-3 text-fg-muted">{{ $location->floor ?? '-' }}</td>
                <td class="px-4 py-3 text-fg-muted tabular-nums">
                    {{ $location->lab_pcs_count }} PC{{ $location->lab_pcs_count === 1 ? '' : 's' }},
                    {{ $location->access_points_count }} AP{{ $location->access_points_count === 1 ? '' : 's' }}
                </td>
                <td class="px-4 py-3 text-right">
                    <a href="{{ route('admin.locations.edit', $location) }}" class="link text-sm">Edit</a>
                </td>
            </tr>
        @endforeach

        <x-slot:empty>
            <x-empty-state title="No locations match those filters"
                           message="Every lab PC and access point has to belong to a location."
                           icon="building">
                <x-slot:action>
                    <a href="{{ route('admin.locations.create') }}" class="btn btn-primary">Add a location</a>
                </x-slot:action>
            </x-empty-state>
        </x-slot:empty>

        <x-slot:pagination><x-pagination :paginator="$locations" /></x-slot:pagination>
    </x-data-table>
</x-app-layout>
