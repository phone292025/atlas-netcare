<x-app-layout>
    <x-slot name="title">Lab PCs</x-slot>

    <x-page-header title="Lab PC inventory"
                   subtitle="{{ $labPcs->total() }} {{ Str::plural('machine', $labPcs->total()) }}. Retired machines are hidden unless you ask for them.">
        <x-slot:actions>
            <a href="{{ route('admin.lab-pcs.create') }}"
               class="inline-flex items-center gap-1.5 rounded-md bg-brand-600 px-3 py-2 text-sm font-medium text-white hover:bg-brand-700">
                <x-icon name="plus" class="h-4 w-4" /> Add machine
            </a>
        </x-slot:actions>
    </x-page-header>

    <x-data-table :headers="[
        ['label' => 'Asset tag', 'sort' => 'asset_tag'],
        'Location',
        ['label' => 'Hostname', 'sort' => 'hostname'],
        ['label' => 'Status', 'sort' => 'status'],
        'Risk',
        '',
    ]">
        <x-slot:filters>
            <form method="GET" class="flex flex-wrap items-end gap-2">
                <div class="w-48">
                    <x-form.input name="search" label="Search" :value="request('search')" placeholder="Asset tag or hostname" />
                </div>
                <div class="w-52">
                    <x-form.select name="location" label="Location" blank="Anywhere" :options="$locations" :selected="request('location')" />
                </div>
                <div class="w-40">
                    <x-form.select name="status" label="Status" blank="Any status" :options="$statuses" :selected="request('status')" />
                </div>
                <label class="flex items-center gap-2 py-2 text-sm text-slate-600">
                    <input type="checkbox" name="retired" value="1" @checked(request()->boolean('retired'))
                           class="rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                    Include retired
                </label>
                <button type="submit" class="rounded-md bg-white px-3 py-2 text-sm font-medium text-slate-700 ring-1 ring-slate-300 hover:bg-slate-50">Filter</button>
                @if (request()->hasAny(['search', 'location', 'status', 'retired']))
                    <a href="{{ route('admin.lab-pcs.index') }}" class="px-2 py-2 text-sm text-slate-500 underline">Clear</a>
                @endif
            </form>
        </x-slot:filters>

        @foreach ($labPcs as $pc)
            <tr class="hover:bg-slate-50 {{ $pc->trashed() ? 'opacity-60' : '' }}">
                <td class="px-4 py-3">
                    <a href="{{ route('admin.lab-pcs.show', $pc) }}" class="font-medium text-slate-900 hover:text-brand-700">{{ $pc->asset_tag }}</a>
                    @if ($pc->trashed())
                        <span class="ml-1 text-xs text-slate-400">retired</span>
                    @endif
                </td>
                <td class="px-4 py-3 text-slate-600">{{ $pc->location?->code }}</td>
                <td class="px-4 py-3 text-slate-600">{{ $pc->hostname ?? '—' }}</td>
                <td class="px-4 py-3"><x-status-badge :status="$pc->status" /></td>
                <td class="px-4 py-3">
                    @if ($pc->risk_band)
                        <x-status-badge :status="$pc->risk_band" />
                    @else
                        {{-- M7 writes this. Until then, saying so beats an empty cell. --}}
                        <span class="text-xs text-slate-400">Not scored yet</span>
                    @endif
                </td>
                <td class="px-4 py-3 text-right">
                    <a href="{{ route('admin.lab-pcs.edit', $pc) }}" class="text-sm text-brand-700 hover:underline">Edit</a>
                </td>
            </tr>
        @endforeach

        <x-slot:empty>
            <x-empty-state title="No machines match those filters"
                           message="Lab PCs are added here and inspected by assistants during their rounds."
                           icon="desktop">
                <x-slot:action>
                    <a href="{{ route('admin.lab-pcs.create') }}" class="rounded-md bg-brand-600 px-3 py-2 text-sm font-medium text-white hover:bg-brand-700">Add the first machine</a>
                </x-slot:action>
            </x-empty-state>
        </x-slot:empty>

        <x-slot:pagination><x-pagination :paginator="$labPcs" /></x-slot:pagination>
    </x-data-table>
</x-app-layout>
