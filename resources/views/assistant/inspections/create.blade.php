<x-app-layout>
    <x-slot name="title">{{ $location->code }}</x-slot>

    {{--
        One room's round, on a phone. The plan shows every machine as a desk,
        in the order the round goes: checked, checked with a fault, the next
        one, and the rest. Under it, one big button starts the next machine,
        so working through a room is one tap per machine. The full list stays
        below, for checking a machine out of order or again.
    --}}

    @php
        $faulty = fn ($labPc) => $labPc->today_outcome !== null
            && $labPc->today_outcome !== \App\Enums\InspectionOutcome::Pass->value;
        $checked = $labPcs->where('inspected_today_count', '>', 0)->count();
        $faults = $labPcs->filter($faulty)->count();
        $total = $labPcs->count();
    @endphp

    <div class="mx-auto max-w-3xl">
        <a href="{{ route('assistant.inspections.index') }}"
           class="mb-4 inline-flex items-center gap-1.5 text-sm text-fg-muted hover:text-fg">
            <x-icon name="arrow-left" class="size-4" /> All locations
        </a>

        <x-page-header :title="'Round in '.$location->name"
                       :subtitle="$total > 0 ? $checked.' of '.$total.' checked today'.($faults > 0 ? ', '.$faults.' with a fault' : '').'.' : null">
            <x-slot:actions>
                <x-badge>{{ $location->code }}</x-badge>
            </x-slot:actions>
        </x-page-header>

        @unless ($hasTemplate)
            <x-alert type="warning" class="mb-6" title="No checklist yet">
                An administrator needs to activate a checklist template before
                inspections can be started.
            </x-alert>
        @endunless

        @if ($total > 0)
            <x-card class="mb-4">
                {{-- The summary above says the same in words, for screen readers. --}}
                <x-round-plan :machines="$labPcs" :next="$next" />

                <div class="mt-4">
                    @if ($next)
                        <form method="POST" action="{{ route('assistant.inspections.store') }}"
                              class="flex flex-wrap items-center justify-between gap-3">
                            @csrf
                            <input type="hidden" name="lab_pc_id" value="{{ $next->id }}">
                            <p class="text-sm text-fg-muted">
                                Next in the room: <span class="font-mono font-medium text-fg">{{ $next->asset_tag }}</span>
                            </p>
                            <x-button type="submit" size="lg" :disabled="! $hasTemplate" class="max-sm:w-full">Inspect {{ $next->asset_tag }}</x-button>
                        </form>
                    @else
                        <p class="flex items-center gap-2 text-sm font-medium text-emerald-700">
                            <x-icon name="check" class="size-4" /> Every machine here is checked today.
                        </p>
                    @endif
                </div>
            </x-card>
        @endif

        <x-card title="All machines" flush>
            <x-slot:header>
                <span class="text-xs text-fg-subtle">Retired machines are not listed.</span>
            </x-slot:header>

            @if ($labPcs->isEmpty())
                <x-empty-state title="No machines here"
                               message="Every machine in this location is retired, or none have been added yet."
                               icon="desktop" />
            @else
                <ul class="divide-y divide-line">
                    @foreach ($labPcs as $labPc)
                        @php
                            $done = $labPc->inspected_today_count > 0;
                            $fault = $faulty($labPc);
                        @endphp
                        <li class="flex items-center gap-3 px-5 py-3">
                            <span @class([
                                'grid size-9 flex-none place-items-center rounded-lg',
                                'bg-red-50 text-red-700' => $fault,
                                'bg-emerald-50 text-emerald-700' => $done && ! $fault,
                                'bg-sunken text-fg-muted' => ! $done,
                            ])>
                                <x-icon :name="$fault ? 'warning' : ($done ? 'check' : 'desktop')" class="size-[18px]" />
                            </span>

                            <div class="min-w-0 flex-1">
                                <p class="font-mono text-sm font-medium text-fg">{{ $labPc->asset_tag }}</p>
                                {{-- The status first, so on a narrow phone it is the
                                     hostname that gets cut short, not the status. --}}
                                <p class="truncate text-xs text-fg-muted">
                                    @if ($fault)
                                        <span data-checked-today class="font-medium text-red-700">Fault found today</span> ·
                                    @elseif ($done)
                                        <span data-checked-today class="font-medium text-emerald-700">Checked today</span> ·
                                    @endif
                                    {{ $labPc->hostname ?? 'No hostname' }}
                                </p>
                            </div>

                            <form method="POST" action="{{ route('assistant.inspections.store') }}" class="flex-none">
                                @csrf
                                <input type="hidden" name="lab_pc_id" value="{{ $labPc->id }}">
                                {{-- Re-checking is allowed: a machine fixed after a
                                     failed inspection needs looking at again the
                                     same day. Secondary, because the button above
                                     is the way through the room. --}}
                                <x-button type="submit" variant="secondary"
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
