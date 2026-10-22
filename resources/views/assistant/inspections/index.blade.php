<x-app-layout>
    <x-slot name="title">Inspections</x-slot>

    <x-page-header title="Inspection rounds"
                   subtitle="Pick a location to start a round, or pick up one you left unfinished." />

    @unless ($hasTemplate)
        {{-- A fresh install. The assistant can do nothing about this, so the
             message names who can. --}}
        <x-alert type="warning" class="mb-6" title="No checklist yet">
            An administrator needs to create and activate a checklist template
            before inspections can be started.
        </x-alert>
    @endunless

    <div class="space-y-8">
        @if ($inProgress->isNotEmpty())
            <section aria-labelledby="unfinished">
                <div class="mb-3 flex items-baseline gap-2">
                    <h3 id="unfinished" class="text-[15px] font-semibold text-fg">Unfinished</h3>
                    <span class="text-sm text-fg-muted">Started but not submitted</span>
                </div>
                <ul class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                    @foreach ($inProgress as $inspection)
                        <li class="flex items-center gap-3 rounded-xl border border-amber-200 bg-amber-50/60 p-4 shadow-xs">
                            <span class="grid size-10 flex-none place-items-center rounded-lg bg-amber-100 text-amber-700">
                                <x-icon name="clipboard" class="size-5" />
                            </span>
                            <div class="min-w-0 flex-1">
                                <p class="font-mono text-sm font-medium text-fg">{{ $inspection->labPc->asset_tag }}</p>
                                <p class="truncate text-xs text-fg-muted">
                                    {{ $inspection->labPc->location?->code }} · started {{ $inspection->started_at?->diffForHumans() }}
                                </p>
                            </div>
                            <x-button size="sm" :href="route('assistant.inspections.edit', $inspection)">Continue</x-button>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif

        <section aria-labelledby="start-round">
            <div class="mb-3 flex items-baseline gap-2">
                <h3 id="start-round" class="text-[15px] font-semibold text-fg">Rounds today</h3>
                <span class="text-sm text-fg-muted">Pick a room to start or carry on</span>
            </div>

            @if ($rooms->isEmpty())
                <x-card>
                    <x-empty-state title="No machines to inspect"
                                   message="Lab PCs are added by an administrator under the asset inventory."
                                   icon="desktop" />
                </x-card>
            @else
                <x-room-rounds :rooms="$rooms" />
            @endif
        </section>

        <x-card title="Completed" subtitle="Your submitted inspections, newest first." flush>
            @if ($completed->isEmpty())
                <x-empty-state title="Nothing submitted yet"
                               message="Inspections you finish will be listed here with their result."
                               icon="clipboard" />
            @else
                <ul class="divide-y divide-line">
                    @foreach ($completed as $inspection)
                        <li>
                            <a href="{{ route('assistant.inspections.show', $inspection) }}"
                               class="flex items-center gap-3 px-5 py-3 transition hover:bg-sunken/60">
                                <span class="min-w-0 flex-1">
                                    <span class="block font-mono text-sm font-medium text-fg">{{ $inspection->labPc->asset_tag }}</span>
                                    <span class="block truncate text-xs text-fg-muted">
                                        {{ $inspection->labPc->location?->code }} · {{ $inspection->completed_at?->diffForHumans() }}
                                    </span>
                                </span>
                                <x-status-badge :status="$inspection->overall_result" class="flex-none" />
                                <x-icon name="chevron-right" class="size-4 flex-none text-fg-subtle" />
                            </a>
                        </li>
                    @endforeach
                </ul>

                @if ($completed->hasPages())
                    <x-slot:footer>
                        <x-pagination :paginator="$completed" />
                    </x-slot:footer>
                @endif
            @endif
        </x-card>
    </div>
</x-app-layout>
