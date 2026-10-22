@props(['machines', 'next' => null, 'current' => null])

{{--
    A room's round as a plan: one desk per machine, in round order. Checked
    is green and a fault red. The machine that matters now is outlined in the
    accent with its tag under it: on the room screen the next one to check,
    on the checklist the one being checked. Machines with a fault carry their
    tag too, so the assistant can say which one without counting desks.

    The desks follow the round's order, by asset tag, not where they stand in
    the room: Atlas does not know that.

    The page says the same in words, so the plan is hidden from screen readers.

    Usage:
        <x-round-plan :machines="$round" :next="$next" />
        <x-round-plan :machines="$round" :current="$inspection->labPc" />

    `machines` come from InspectionService::roundIn().
--}}

@php
    $focus = $current ?? $next;
    $focusState = $current ? 'current' : 'next';

    $stateOf = function ($machine) use ($focus, $focusState): string {
        $fault = $machine->today_outcome !== null
            && $machine->today_outcome !== \App\Enums\InspectionOutcome::Pass->value;

        return match (true) {
            $focus?->is($machine) === true => $focusState,
            $fault => 'fault',
            $machine->inspected_today_count > 0 => 'checked',
            default => 'todo',
        };
    };
@endphp

<div {{ $attributes->merge(['class' => 'rounded-lg border border-line-strong px-3 pb-2 pt-3']) }} aria-hidden="true">
    <div class="grid grid-cols-7 gap-x-2 gap-y-6 pb-4 sm:grid-cols-10">
        @foreach ($machines as $machine)
            @php($state = $stateOf($machine))
            <span class="relative" data-desk="{{ $state }}" title="{{ $machine->asset_tag }}">
                <span @class([
                    'flex h-8 items-start justify-center rounded-md pt-1.5',
                    'border border-emerald-500 bg-emerald-50' => $state === 'checked',
                    'border border-red-500 bg-red-50' => $state === 'fault',
                    'border-2 border-brand-500' => $state === 'next' || $state === 'current',
                    'border border-line-strong' => $state === 'todo',
                ])>
                    <span @class([
                        'h-0.5 w-1/2 rounded-full',
                        'bg-emerald-500' => $state === 'checked',
                        'bg-red-500' => $state === 'fault',
                        'bg-brand-500' => $state === 'next' || $state === 'current',
                        'bg-line-strong' => $state === 'todo',
                    ])></span>
                </span>
                @if ($state !== 'checked' && $state !== 'todo')
                    <span @class([
                        'absolute left-1/2 top-full mt-1 -translate-x-1/2 whitespace-nowrap font-mono text-[10px] font-semibold',
                        'text-red-700' => $state === 'fault',
                        'text-brand-700' => $state !== 'fault',
                    ])>{{ $machine->asset_tag }}</span>
                @endif
            </span>
        @endforeach
    </div>

    <div class="flex flex-wrap gap-x-4 gap-y-1 border-t border-line pt-2 text-xs text-fg-muted">
        <span class="inline-flex items-center gap-1.5"><span class="h-2.5 w-3.5 rounded-sm border border-emerald-500 bg-emerald-50"></span>Checked</span>
        <span class="inline-flex items-center gap-1.5"><span class="h-2.5 w-3.5 rounded-sm border border-red-500 bg-red-50"></span>Fault</span>
        <span class="inline-flex items-center gap-1.5"><span class="h-2.5 w-3.5 rounded-sm border-2 border-brand-500"></span>{{ $current ? 'This machine' : 'Next' }}</span>
        <span class="inline-flex items-center gap-1.5"><span class="h-2.5 w-3.5 rounded-sm border border-line-strong"></span>To do</span>
    </div>
</div>
