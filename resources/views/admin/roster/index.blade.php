<x-app-layout>
    <x-slot name="title">Roster</x-slot>

    <x-page-header title="Roster"
                   :subtitle="'Week of '.$week->format('j M Y').' · '.$shiftCount.' '.Str::plural('shift', $shiftCount)">
        <x-slot:actions>
            <a href="{{ route('admin.roster.index', ['week' => $week->copy()->subWeek()->toDateString()]) }}"
               class="rounded-md bg-white px-3 py-2 text-sm font-medium text-slate-700 ring-1 ring-slate-300 hover:bg-slate-50">Previous</a>
            <a href="{{ route('admin.roster.index', ['week' => $week->copy()->addWeek()->toDateString()]) }}"
               class="rounded-md bg-white px-3 py-2 text-sm font-medium text-slate-700 ring-1 ring-slate-300 hover:bg-slate-50">Next</a>

            <form method="POST" action="{{ route('admin.roster.generate') }}">
                @csrf
                <input type="hidden" name="week" value="{{ $week->toDateString() }}">
                <button type="submit" @disabled($templateCount === 0)
                        class="rounded-md bg-brand-600 px-3 py-2 text-sm font-medium text-white hover:bg-brand-700
                               disabled:cursor-not-allowed disabled:opacity-50">
                    Generate this week
                </button>
            </form>
        </x-slot:actions>
    </x-page-header>

    @if ($templateCount === 0)
        <x-alert type="warning" class="mb-4" title="No active shift templates">
            The generator builds a week from the weekly patterns, so there is nothing for it to do yet.
            <a href="{{ route('admin.shift-templates.create') }}" class="font-medium underline">Add a template</a> first.
        </x-alert>
    @endif

    {{-- Seven columns on a desktop, stacking on a phone. Generating is safe to
         press twice — the service is idempotent — because the obvious reaction
         to a slow page is to press the button again. --}}
    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4 xl:grid-cols-7">
        @foreach ($days as $day)
            @php($shifts = $byDay->get($day->toDateString(), collect()))

            <div class="rounded-lg bg-white p-4 ring-1 {{ $day->isToday() ? 'ring-brand-400' : 'ring-slate-200' }}">
                <p class="text-xs font-medium uppercase tracking-wide {{ $day->isToday() ? 'text-brand-700' : 'text-slate-500' }}">
                    {{ $day->format('D j M') }}
                </p>

                @if ($shifts->isEmpty())
                    <p class="mt-2 text-sm text-slate-400">Nobody on</p>
                @else
                    <ul class="mt-2 space-y-2.5">
                        @foreach ($shifts as $shift)
                            <li class="border-l-2 border-slate-200 pl-2">
                                <p class="text-sm font-medium text-slate-900">{{ $shift->user?->name }}</p>
                                <p class="text-xs text-slate-500">
                                    {{ substr($shift->start_time, 0, 5) }}–{{ substr($shift->end_time, 0, 5) }}
                                    · {{ $shift->location?->code }}
                                </p>
                                <x-status-badge :status="$shift->status" class="mt-1" />
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        @endforeach
    </div>
</x-app-layout>
