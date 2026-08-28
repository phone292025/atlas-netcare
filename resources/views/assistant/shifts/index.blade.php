<x-app-layout>
    <x-slot name="title">My roster</x-slot>

    <x-page-header title="My roster"
                   :subtitle="'Week of '.$week->format('j M Y')">
        <x-slot:actions>
            <a href="{{ route('assistant.shifts.index', ['week' => $week->copy()->subWeek()->toDateString()]) }}"
               class="rounded-md bg-white px-3 py-2 text-sm font-medium text-slate-700 ring-1 ring-slate-300 hover:bg-slate-50">Previous</a>
            <a href="{{ route('assistant.shifts.index', ['week' => $week->copy()->addWeek()->toDateString()]) }}"
               class="rounded-md bg-white px-3 py-2 text-sm font-medium text-slate-700 ring-1 ring-slate-300 hover:bg-slate-50">Next</a>
        </x-slot:actions>
    </x-page-header>

    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        @foreach ($days as $day)
            @php($shifts = $byDay->get($day->toDateString(), collect()))

            <div class="rounded-lg bg-white p-4 ring-1 {{ $day->isToday() ? 'ring-brand-400' : 'ring-slate-200' }}">
                <p class="text-xs font-medium uppercase tracking-wide {{ $day->isToday() ? 'text-brand-700' : 'text-slate-500' }}">
                    {{ $day->format('D j M') }}{{ $day->isToday() ? ' · today' : '' }}
                </p>

                @if ($shifts->isEmpty())
                    <p class="mt-2 text-sm text-slate-400">Off</p>
                @else
                    <ul class="mt-2 space-y-2">
                        @foreach ($shifts as $shift)
                            <li>
                                <p class="text-sm font-medium text-slate-900">
                                    {{ substr($shift->start_time, 0, 5) }}–{{ substr($shift->end_time, 0, 5) }}
                                </p>
                                <p class="text-xs text-slate-500">{{ $shift->location?->code }}</p>
                                <x-status-badge :status="$shift->status" class="mt-1" />
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        @endforeach
    </div>
</x-app-layout>
