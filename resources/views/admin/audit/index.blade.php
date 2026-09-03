<x-app-layout>
    <x-slot name="title">Audit log</x-slot>

    <x-page-header title="Audit log"
                   subtitle="Every create, change and removal, attributed to a person and a time." />

    {{-- Read-only on purpose. There is no edit or delete here: a log somebody
         can change is not evidence of anything. NFR-06, FR-409. --}}
    <x-data-table :headers="['When', 'Who', 'Action', 'Subject', 'Detail']">
        <x-slot:filters>
            <form method="GET" class="flex flex-wrap items-end gap-2">
                <div class="w-40">
                    <x-form.select name="action" label="Action" placeholder="Any action"
                                   :options="$actions" :selected="request('action')" />
                </div>
                <div class="w-48">
                    <x-form.select name="type" label="Subject" placeholder="Anything"
                                   :options="$types" :selected="request('type')" />
                </div>
                <button type="submit"
                        class="rounded-md bg-white px-3 py-2 text-sm font-medium text-slate-700 ring-1 ring-slate-300 hover:bg-slate-50">
                    Filter
                </button>
                @if (request()->hasAny(['action', 'type', 'user']))
                    <a href="{{ route('admin.audit.index') }}" class="px-2 py-2 text-sm text-slate-500 underline">Clear</a>
                @endif
            </form>
        </x-slot:filters>

        @foreach ($logs as $log)
            <tr class="hover:bg-slate-50">
                <td class="px-4 py-3 whitespace-nowrap">
                    <time datetime="{{ $log->created_at->toIso8601String() }}"
                          title="{{ $log->created_at->format('D j M Y, H:i:s') }}"
                          class="text-slate-600">
                        {{ $log->created_at->diffForHumans() }}
                    </time>
                </td>
                <td class="px-4 py-3">
                    {{-- Null when a seeder or a scheduled command did it. --}}
                    {{ $log->user?->name ?? 'System' }}
                </td>
                <td class="px-4 py-3">
                    <x-badge :tone="match ($log->action) {
                        'created', 'restored' => 'healthy',
                        'archived', 'deleted' => 'urgent',
                        default => 'attention',
                    }">{{ ucfirst($log->action) }}</x-badge>
                </td>
                <td class="px-4 py-3 text-slate-600">
                    <span class="text-slate-900">{{ $log->subjectLabel() }}</span>
                    <span class="block text-xs">{{ $log->description }}</span>
                </td>
                {{-- "priority: low → critical", not just "priority". The model
                     builds the strings; this decides nothing.
                     @see App\Models\AuditLog::changeSummary() --}}
                <td class="px-4 py-3 text-xs text-slate-500">
                    @forelse ($log->changeSummary() as $line)
                        <span class="block">{{ $line }}</span>
                    @empty
                        —
                    @endforelse
                </td>
            </tr>
        @endforeach

        <x-slot:empty>
            <x-empty-state title="Nothing recorded yet"
                           message="Creating or changing a user, location or asset will appear here."
                           icon="shield" />
        </x-slot:empty>

        <x-slot:pagination>
            <x-pagination :paginator="$logs" />
        </x-slot:pagination>
    </x-data-table>
</x-app-layout>
