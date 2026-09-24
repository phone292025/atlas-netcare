<x-app-layout>
    <x-slot name="title">Shift templates</x-slot>

    <x-page-header title="Shift templates"
                   subtitle="Weekly patterns. The roster generator turns these into dated shifts.">
        <x-slot:actions>
            <a href="{{ route('admin.roster.index') }}"
               class="btn btn-secondary">
                Roster
            </a>
            <a href="{{ route('admin.shift-templates.create') }}"
               class="btn btn-primary">
                <x-icon name="plus" class="size-4" /> New template
            </a>
        </x-slot:actions>
    </x-page-header>

    <x-data-table :headers="['Template', 'When', 'Location', 'Assistants', 'Shifts made', 'Status', '']">
        @foreach ($templates as $template)
            <tr class="hover:bg-sunken/70 {{ $template->is_active ? '' : 'opacity-60' }}">
                <td class="px-4 py-3">
                    <a href="{{ route('admin.shift-templates.edit', $template) }}"
                       class="font-medium text-fg hover:text-brand-700">{{ $template->name }}</a>
                </td>
                <td class="whitespace-nowrap px-4 py-3 text-fg-muted">{{ $template->label() }}</td>
                <td class="px-4 py-3 text-fg-muted">{{ $template->location?->code }}</td>
                <td class="px-4 py-3 tabular-nums text-fg-muted">{{ $template->required_assistants }}</td>
                <td class="px-4 py-3 tabular-nums text-fg-muted">{{ $template->shifts_count }}</td>
                <td class="px-4 py-3">
                    <x-badge :tone="$template->is_active ? 'healthy' : 'neutral'">
                        {{ $template->is_active ? 'Active' : 'Inactive' }}
                    </x-badge>
                </td>
                <td class="px-4 py-3 text-right">
                    <a href="{{ route('admin.shift-templates.edit', $template) }}" class="link text-sm">Edit</a>
                </td>
            </tr>
        @endforeach

        <x-slot:empty>
            <x-empty-state title="No shift patterns yet"
                           message="Define the weekly slots first. The roster generator has nothing to work from without them."
                           icon="calendar">
                <x-slot:action>
                    <a href="{{ route('admin.shift-templates.create') }}" class="btn btn-primary">Add the first template</a>
                </x-slot:action>
            </x-empty-state>
        </x-slot:empty>

        <x-slot:pagination><x-pagination :paginator="$templates" /></x-slot:pagination>
    </x-data-table>
</x-app-layout>
