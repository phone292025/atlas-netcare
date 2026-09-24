<x-app-layout>
    <x-slot name="title">My roster</x-slot>

    {{-- An agenda, one row a day. Seven columns squeeze to nothing on a phone,
         and a week of shifts is a list anyway: read it top to bottom. --}}

    @php
        $all = $byDay->flatten(1);
        $minutes = $all->sum(fn ($s) => \Carbon\Carbon::parse($s->start_time)->diffInMinutes(\Carbon\Carbon::parse($s->end_time)));
        $hours = rtrim(rtrim(number_format($minutes / 60, 1), '0'), '.');
        $end = $week->copy()->endOfWeek();
        $isThisWeek = $week->isSameDay(\App\Support\CampusClock::now()->startOfWeek());
    @endphp

    <div class="mx-auto max-w-3xl">
        <x-page-header title="My roster"
                       :subtitle="$all->isEmpty()
                           ? 'Nothing rostered this week.'
                           : $all->count().' '.Str::plural('shift', $all->count()).', '.$hours.' hours.'">
            <x-slot:actions>
                <div class="flex items-center gap-2">
                    @unless ($isThisWeek)
                        <x-button variant="ghost" size="sm" :href="route('assistant.shifts.index')">This week</x-button>
                    @endunless
                    <div class="inline-flex items-center rounded-lg border border-line bg-surface shadow-xs">
                        <a href="{{ route('assistant.shifts.index', ['week' => $week->copy()->subWeek()->toDateString()]) }}"
                           class="grid size-9 place-items-center rounded-l-lg text-fg-muted hover:bg-sunken hover:text-fg" aria-label="Previous week">
                            <x-icon name="chevron-left" class="size-4" />
                        </a>
                        <span class="border-x border-line px-3 text-sm font-medium tabular-nums text-fg">
                            {{ $week->format('j M') }} to {{ $end->format('j M Y') }}
                        </span>
                        <a href="{{ route('assistant.shifts.index', ['week' => $week->copy()->addWeek()->toDateString()]) }}"
                           class="grid size-9 place-items-center rounded-r-lg text-fg-muted hover:bg-sunken hover:text-fg" aria-label="Next week">
                            <x-icon name="chevron-right" class="size-4" />
                        </a>
                    </div>
                </div>
            </x-slot:actions>
        </x-page-header>

        <ol class="divide-y divide-line overflow-hidden rounded-xl border border-line bg-surface shadow-xs">
            @foreach ($days as $day)
                @php
                    $shifts = $byDay->get($day->toDateString(), collect());
                    $today = $day->isToday();
                @endphp

                <li @class(['flex gap-4 px-4 py-3.5 sm:px-5', 'bg-brand-50/40' => $today])>
                    <div @class([
                        'grid w-12 flex-none place-items-center self-start rounded-lg border py-1.5 text-center leading-none',
                        'border-brand-600 bg-brand-600 text-white' => $today,
                        'border-line bg-sunken' => ! $today,
                    ])>
                        <span @class(['text-2xs font-medium uppercase', 'text-white/80' => $today, 'text-fg-muted' => ! $today])>{{ $day->format('D') }}</span>
                        <span @class(['mt-0.5 text-lg font-semibold tabular-nums', 'text-fg' => ! $today])>{{ $day->format('j') }}</span>
                    </div>

                    <div class="min-w-0 flex-1 self-center">
                        @if ($shifts->isEmpty())
                            <p class="text-sm text-fg-subtle">Off{{ $today ? ' today' : '' }}</p>
                        @else
                            <ul class="space-y-2.5">
                                @foreach ($shifts as $shift)
                                    <li class="flex flex-wrap items-center justify-between gap-x-3 gap-y-1">
                                        <div class="min-w-0">
                                            <p class="text-sm font-medium tabular-nums text-fg">
                                                {{ substr($shift->start_time, 0, 5) }}-{{ substr($shift->end_time, 0, 5) }}
                                            </p>
                                            <p class="flex items-center gap-1 truncate text-xs text-fg-muted">
                                                <x-icon name="map-pin" class="size-3 flex-none" />
                                                {{ $shift->location?->label() ?? 'No location' }}
                                            </p>
                                        </div>
                                        <x-status-badge :status="$shift->status" class="flex-none" />
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                </li>
            @endforeach
        </ol>
    </div>
</x-app-layout>
