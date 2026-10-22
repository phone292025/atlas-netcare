@props(['rooms', 'highlight' => null, 'columns' => 3])

{{--
    Today's rounds, room by room: one small mark per machine (checked, fault,
    to do) and how far the round has got in words. Each room opens its room
    screen, where the round goes on. `highlight` is the room of today's
    shift, by location id. `columns` is how many cards fit across a wide
    screen: 3 on a page of their own, 2 beside a sidebar, where three left
    each card too narrow for a lab's row of marks.

    Usage:
        <x-room-rounds :rooms="$rooms" />
        <x-room-rounds :rooms="$rooms" :highlight="$shift?->location_id" :columns="2" />

    `rooms` come from InspectionService::roundsToday().
--}}

<ul {{ $attributes->class(['grid gap-3 sm:grid-cols-2', 'xl:grid-cols-3' => $columns >= 3]) }}>
    @foreach ($rooms as $room)
        @php
            $machines = $room->round;
            $total = $machines->count();
            $isFault = fn ($machine) => $machine->today_outcome !== null
                && $machine->today_outcome !== \App\Enums\InspectionOutcome::Pass->value;
            $checked = $machines->filter(fn ($machine) => $machine->inspected_today_count > 0)->count();
            $faults = $machines->filter($isFault)->count();
            [$progress, $progressTone] = match (true) {
                $checked === 0 => ['Not started today', 'text-fg-subtle'],
                $checked === $total => ['All '.$total.' checked today', 'font-medium text-emerald-700'],
                default => [$checked.' of '.$total.' checked today', 'text-fg-muted'],
            };
            $yours = $highlight !== null && $room->id === $highlight;
        @endphp
        <li>
            <a href="{{ route('assistant.inspections.create', ['location' => $room->id]) }}"
               @class([
                   'group flex h-full flex-col gap-3 rounded-xl border bg-surface p-4 shadow-xs transition hover:border-line-strong hover:shadow-sm',
                   'border-brand-500 ring-1 ring-brand-500/30' => $yours,
                   'border-line' => ! $yours,
               ])>
                <span class="flex items-start justify-between gap-3">
                    <span class="min-w-0">
                        <span class="block truncate text-sm font-medium text-fg">{{ $room->name }}</span>
                        <span class="block text-xs text-fg-muted">
                            <span class="font-mono">{{ $room->code }}</span>
                            @if ($yours)
                                · <span class="font-medium text-brand-700">Your shift is here</span>
                            @endif
                        </span>
                    </span>
                    <x-icon name="chevron-right" class="mt-0.5 size-4 flex-none text-fg-subtle transition group-hover:translate-x-0.5" />
                </span>

                <span class="flex flex-wrap gap-1" aria-hidden="true">
                    @foreach ($machines as $machine)
                        <span @class([
                            'h-2 w-3 rounded-sm',
                            'bg-red-500' => $isFault($machine),
                            'bg-emerald-500' => ! $isFault($machine) && $machine->inspected_today_count > 0,
                            'bg-line-strong' => ! ($machine->inspected_today_count > 0),
                        ])></span>
                    @endforeach
                </span>

                <span class="text-sm {{ $progressTone }}">{{ $progress }}{{ $faults > 0 ? ', '.$faults.' '.\Illuminate\Support\Str::plural('fault', $faults) : '' }}</span>
            </a>
        </li>
    @endforeach
</ul>
