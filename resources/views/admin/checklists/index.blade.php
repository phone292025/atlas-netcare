<x-app-layout>
    <x-slot name="title">Checklists</x-slot>

    <x-page-header title="Checklist templates"
                   subtitle="What an assistant is asked at each machine. Editing a used checklist creates a new version rather than changing history.">
        <x-slot:actions>
            <a href="{{ route('admin.checklists.create') }}"
               class="inline-flex items-center gap-1.5 rounded-md bg-brand-600 px-3 py-2 text-sm font-medium text-white hover:bg-brand-700">
                <x-icon name="plus" class="h-4 w-4" /> New checklist
            </a>
        </x-slot:actions>
    </x-page-header>

    <x-data-table :headers="['Checklist', 'Version', 'Items', 'Used by', 'Status', '']">
        @foreach ($templates as $template)
            <tr class="hover:bg-slate-50">
                <td class="px-4 py-3">
                    <a href="{{ route('admin.checklists.edit', $template) }}"
                       class="font-medium text-slate-900 hover:text-brand-700">{{ $template->name }}</a>
                    @if (filled($template->description))
                        <span class="block text-xs text-slate-400">{{ Str::limit($template->description, 70) }}</span>
                    @endif
                </td>
                <td class="px-4 py-3 tabular-nums text-slate-600">v{{ $template->version }}</td>
                <td class="px-4 py-3 tabular-nums text-slate-600">{{ $template->items_count }}</td>
                <td class="px-4 py-3 tabular-nums text-slate-600">
                    {{ $template->inspections_count }} {{ Str::plural('inspection', $template->inspections_count) }}
                </td>
                <td class="px-4 py-3">
                    @if ($template->is_active)
                        <x-badge tone="healthy">Active</x-badge>
                    @elseif ($template->inspections_count > 0)
                        {{-- Not active but not disposable either: old inspections
                             render from these items. --}}
                        <x-badge tone="neutral">Superseded</x-badge>
                    @else
                        <x-badge tone="neutral">Draft</x-badge>
                    @endif
                </td>
                <td class="px-4 py-3 text-right">
                    @unless ($template->is_active)
                        <form method="POST" action="{{ route('admin.checklists.activate', $template) }}" class="inline">
                            @csrf
                            @method('PATCH')
                            <button type="submit" class="text-sm text-brand-700 hover:underline">Make active</button>
                        </form>
                    @endunless
                </td>
            </tr>
        @endforeach

        <x-slot:empty>
            <x-empty-state title="No checklists yet"
                           message="Assistants cannot start an inspection until one checklist is active."
                           icon="clipboard">
                <x-slot:action>
                    <a href="{{ route('admin.checklists.create') }}" class="rounded-md bg-brand-600 px-3 py-2 text-sm font-medium text-white hover:bg-brand-700">Build the first checklist</a>
                </x-slot:action>
            </x-empty-state>
        </x-slot:empty>

        <x-slot:pagination><x-pagination :paginator="$templates" /></x-slot:pagination>
    </x-data-table>
</x-app-layout>
