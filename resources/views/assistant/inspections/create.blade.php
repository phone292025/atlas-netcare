<x-app-layout>
    <x-slot name="title">{{ $location->code }}</x-slot>

    @php
        $checked = $labPcs->where('inspected_today_count', '>', 0)->count();
        $total = $labPcs->count();
    @endphp

    <div class="mx-auto max-w-3xl">
        <a href="{{ route('assistant.inspections.index') }}"
           class="mb-4 inline-flex items-center gap-1.5 text-sm text-fg-muted hover:text-fg">
            <x-icon name="arrow-left" class="size-4" /> All locations
        </a>

        <x-page-header :title="$location->code" :subtitle="$location->name" />

        @unless ($hasTemplate)
            <x-alert type="warning" class="mb-6" title="No checklist yet">
                An administrator needs to activate a checklist template before
                inspections can be started.
            </x-alert>
        @endunless

        <x-card title="Machines in this location" flush>
            <x-slot:header>
                @if ($total > 0)
                    <span class="text-sm tabular-nums text-fg-muted">{{ $checked }} of {{ $total }} checked today</span>
                @endif
            </x-slot:header>

            @if ($total > 0)
                <div class="border-b border-line px-5 py-3" aria-hidden="true">
                    <div class="h-1.5 overflow-hidden rounded-full bg-sunken">
                        <div class="h-full rounded-full bg-emerald-500" style="width: {{ (int) round($checked / $total * 100) }}%"></div>
                    </div>
                    <p class="mt-2 text-xs text-fg-subtle">Retired machines are not listed.</p>
                </div>
            @endif

            @if ($labPcs->isEmpty())
                <x-empty-state title="No machines here"
                               message="Every machine in this location is retired, or none have been added yet."
                               icon="desktop" />
            @else
                <ul class="divide-y divide-line">
                    @foreach ($labPcs as $labPc)
                        @php($done = $labPc->inspected_today_count > 0)
                        <li class="flex items-center gap-3 px-5 py-3">
                            <span @class([
                                'grid size-9 flex-none place-items-center rounded-lg',
                                'bg-emerald-50 text-emerald-700' => $done,
                                'bg-sunken text-fg-muted' => ! $done,
                            ])>
                                <x-icon :name="$done ? 'check' : 'desktop'" class="size-[18px]" />
                            </span>

                            <div class="min-w-0 flex-1">
                                <p class="font-mono text-sm font-medium text-fg">{{ $labPc->asset_tag }}</p>
                                <p class="truncate text-xs text-fg-muted">
                                    {{ $labPc->hostname ?? 'No hostname' }}
                                    @if ($done)
                                        · <span data-checked-today class="text-emerald-700">Checked today</span>
                                    @endif
                                </p>
                            </div>

                            <form method="POST" action="{{ route('assistant.inspections.store') }}" class="flex-none">
                                @csrf
                                <input type="hidden" name="lab_pc_id" value="{{ $labPc->id }}">
                                {{-- Re-checking is allowed: a machine fixed after a
                                     failed inspection needs looking at again the
                                     same day. --}}
                                <x-button type="submit" :variant="$done ? 'secondary' : 'primary'"
                                          :disabled="! $hasTemplate" class="min-h-[44px] sm:min-h-0">
                                    {{ $done ? 'Check again' : 'Inspect' }}
                                </x-button>
                            </form>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-card>
    </div>
</x-app-layout>
