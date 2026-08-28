<x-app-layout>
    <x-slot name="title">Shift templates</x-slot>

    <x-page-header title="Shift templates"
                   subtitle="Weekly patterns. The roster generator turns these into dated shifts.">
        <x-slot:actions>
            <a href="{{ route('admin.roster.index') }}"
               class="rounded-md bg-white px-3 py-2 text-sm font-medium text-slate-700 ring-1 ring-slate-300 hover:bg-slate-50">
                Roster
            </a>
            <a href="{{ route('admin.shift-templates.create') }}"
               class="inline-flex items-center gap-1.5 rounded-md bg-brand-600 px-3 py-2 text-sm font-medium text-white hover:bg-brand-700">
                <x-icon name="plus" class="h-4 w-4" /> New template
            </a>
        </x-slot:actions>
    </x-page-header>

    <x-data-table :headers="['Template', 'When', 'Location', 'Assistants', 'Shifts made', 'Status', '']">
        @foreach ($templates as $template)
            <tr class="hover:bg-slate-50 {{ $template->is_active ? '' : 'opacity-60' }}">
                <td class="px-4 py-3">
                    <a href="{{ route('admin.shift-templates.edit', $template) }}"
                       class="font-medium text-slate-900 hover:text-brand-700">{{ $template->name }}</a>
                </td>
                <td class="whitespace-nowrap px-4 py-3 text-slate-600">{{ $template->label() }}</td>
                <td class="px-4 py-3 text-slate-600">{{ $template->location?->code }}</td>
                <td class="px-4 py-3 tabular-nums text-slate-600">{{ $template->required_assistants }}</td>
                <td class="px-4 py-3 tabular-nums text-slate-600">{{ $template->shifts_count }}</td>
                <td class="px-4 py-3">
                    <x-badge :tone="$template->is_active ? 'healthy' : 'neutral'">
                        {{ $template->is_active ? 'Active' : 'Inactive' }}
                    </x-badge>
                </td>
                <td class="px-4 py-3 text-right">
                    <a href="{{ route('admin.shift-templates.edit', $template) }}" class="text-sm text-brand-700 hover:underline">Edit</a>
                </td>
            </tr>
        @endforeach

        <x-slot:empty>
            <x-empty-state title="No shift patterns yet"
                           message="Define the weekly slots first — the roster generator has nothing to work from without them."
                           icon="calendar">
                <x-slot:action>
                    <a href="{{ route('admin.shift-templates.create') }}" class="rounded-md bg-brand-600 px-3 py-2 text-sm font-medium text-white hover:bg-brand-700">Add the first template</a>
                </x-slot:action>
            </x-empty-state>
        </x-slot:empty>

        <x-slot:pagination><x-pagination :paginator="$templates" /></x-slot:pagination>
    </x-data-table>
</x-app-layout>
