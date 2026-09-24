<x-app-layout>
    <x-slot name="title">Audit log</x-slot>

    <x-page-header title="Audit log"
                   subtitle="Every create, change and removal, attributed to a person and a time." />

    {{-- Read-only on purpose. There is no edit or delete here: a log somebody
         can change is not evidence of anything. NFR-06, FR-409. --}}
    <form method="GET" class="mb-4 flex flex-wrap items-center gap-2">
        @if (request()->filled('user'))
            <input type="hidden" name="user" value="{{ request('user') }}">
        @endif

        <select name="action" aria-label="Action" onchange="this.form.requestSubmit()" class="h-9 w-auto py-0 pl-3 pr-8 text-sm">
            <option value="">Any action</option>
            @foreach ($actions as $value => $label)
                <option value="{{ $value }}" @selected(request('action') === (string) $value)>{{ ucfirst($label) }}</option>
            @endforeach
        </select>

        <select name="type" aria-label="Subject" onchange="this.form.requestSubmit()" class="h-9 w-auto py-0 pl-3 pr-8 text-sm">
            <option value="">Anything</option>
            @foreach ($types as $value => $label)
                <option value="{{ $value }}" @selected(request('type') === (string) $value)>{{ $label }}</option>
            @endforeach
        </select>

        <noscript><x-button type="submit" variant="secondary" size="sm">Filter</x-button></noscript>

        @if (request()->hasAny(['action', 'type', 'user']))
            <x-button variant="ghost" size="sm" icon="close" :href="route('admin.audit.index')">Clear filters</x-button>
        @endif
    </form>

    <x-data-table :headers="['When', 'Who', 'Action', 'Subject', 'Detail']">
        @foreach ($logs as $log)
            <tr class="hover:bg-sunken/70">
                <td class="px-4 py-3 whitespace-nowrap">
                    <time datetime="{{ $log->created_at->toIso8601String() }}"
                          title="{{ $log->created_at->campus()->format('D j M Y, H:i:s') }}">
                        <span class="block text-fg">{{ $log->created_at->diffForHumans() }}</span>
                        <span class="block text-xs tabular-nums text-fg-subtle">{{ $log->created_at->campus()->format('j M, H:i') }}</span>
                    </time>
                </td>
                <td class="px-4 py-3">
                    {{-- Null when a seeder or a scheduled command did it. --}}
                    <span class="flex items-center gap-2 whitespace-nowrap text-fg">
                        @if ($log->user)
                            <x-avatar :name="$log->user->name" />
                        @else
                            <span class="grid size-6 place-items-center rounded-md bg-sunken text-fg-subtle"><x-icon name="gear" class="size-3.5" /></span>
                        @endif
                        {{ $log->user?->name ?? 'System' }}
                    </span>
                </td>
                <td class="px-4 py-3">
                    <x-badge :tone="match ($log->action) {
                        'created', 'restored' => 'healthy',
                        'archived', 'deleted' => 'urgent',
                        default => 'attention',
                    }">{{ ucfirst($log->action) }}</x-badge>
                </td>
                <td class="px-4 py-3 text-fg-muted">
                    <span class="text-fg">{{ $log->subjectLabel() }}</span>
                    <span class="block text-xs">{{ $log->description }}</span>
                </td>
                {{-- "priority: low → critical", not just "priority". The model
                     builds the strings; this decides nothing.
                     @see App\Models\AuditLog::changeSummary() --}}
                <td class="px-4 py-3 text-xs text-fg-muted">
                    @forelse ($log->changeSummary($labels) as $line)
                        <span class="block">{{ $line }}</span>
                    @empty
                        <span class="text-fg-subtle">-</span>
                    @endforelse
                </td>
            </tr>
        @endforeach

        <x-slot:empty>
            <x-empty-state title="Nothing recorded"
                           message="Creating or changing a user, location or asset will appear here."
                           icon="shield" />
        </x-slot:empty>

        <x-slot:pagination>
            <x-pagination :paginator="$logs" />
        </x-slot:pagination>
    </x-data-table>
</x-app-layout>
