<x-app-layout>
    <x-slot name="title">Inspections</x-slot>

    <x-page-header title="Inspection rounds"
                   subtitle="Pick a location to start a round, or pick up one you left unfinished." />

    @unless ($hasTemplate)
        {{-- A fresh install. The assistant can do nothing about this, so the
             message names who can. --}}
        <x-alert type="warning" class="mb-4" title="No checklist yet">
            An administrator needs to create and activate a checklist template
            before inspections can be started.
        </x-alert>
    @endunless

    @if ($inProgress->isNotEmpty())
        <x-card title="Unfinished" subtitle="Started but not submitted." class="mb-4">
            <ul class="divide-y divide-slate-100">
                @foreach ($inProgress as $inspection)
                    <li class="flex items-center justify-between gap-3 py-3 first:pt-0 last:pb-0">
                        <div class="min-w-0">
                            <p class="text-sm font-medium text-slate-900">{{ $inspection->labPc->asset_tag }}</p>
                            <p class="text-xs text-slate-500">
                                {{ $inspection->labPc->location?->code }} ·
                                started {{ $inspection->started_at?->diffForHumans() }}
                            </p>
                        </div>
                        <a href="{{ route('assistant.inspections.edit', $inspection) }}"
                           class="flex-none rounded-md bg-brand-600 px-3 py-2 text-sm font-medium text-white hover:bg-brand-700">
                            Continue
                        </a>
                    </li>
                @endforeach
            </ul>
        </x-card>
    @endif

    <x-card title="Start a round" subtitle="Locations with machines in them." class="mb-4">
        @if ($locations->isEmpty())
            <x-empty-state title="No machines to inspect"
                           message="Lab PCs are added by an administrator under the asset inventory."
                           icon="desktop" />
        @else
            <ul class="grid gap-2 sm:grid-cols-2">
                @foreach ($locations as $location)
                    <li>
                        <a href="{{ route('assistant.inspections.create', ['location' => $location->id]) }}"
                           class="flex items-center justify-between gap-3 rounded-lg px-4 py-3 ring-1 ring-slate-200 hover:bg-slate-50">
                            <span class="min-w-0">
                                <span class="block text-sm font-medium text-slate-900">{{ $location->code }}</span>
                                <span class="block text-xs text-slate-500">{{ $location->name }}</span>
                            </span>
                            <span class="flex-none text-xs text-slate-400">
                                {{ $location->lab_pcs_count }} {{ Str::plural('PC', $location->lab_pcs_count) }}
                            </span>
                        </a>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-card>

    <x-card title="Completed" subtitle="Your submitted inspections." flush>
        @if ($completed->isEmpty())
            <x-empty-state title="Nothing submitted yet"
                           message="Inspections you finish will be listed here with their result."
                           icon="clipboard" />
        @else
            <ul class="divide-y divide-slate-100">
                @foreach ($completed as $inspection)
                    <li>
                        <a href="{{ route('assistant.inspections.show', $inspection) }}"
                           class="flex items-center justify-between gap-3 px-6 py-3 hover:bg-slate-50">
                            <span class="min-w-0">
                                <span class="block text-sm font-medium text-slate-900">{{ $inspection->labPc->asset_tag }}</span>
                                <span class="block text-xs text-slate-500">
                                    {{ $inspection->labPc->location?->code }} ·
                                    {{ $inspection->completed_at?->diffForHumans() }}
                                </span>
                            </span>
                            <x-status-badge :status="$inspection->overall_result" class="flex-none" />
                        </a>
                    </li>
                @endforeach
            </ul>

            <div class="border-t border-slate-200 px-6 py-3">
                <x-pagination :paginator="$completed" />
            </div>
        @endif
    </x-card>
</x-app-layout>
