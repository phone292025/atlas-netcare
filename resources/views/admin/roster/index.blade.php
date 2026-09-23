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

            @if ($removableCount > 0)
                <button type="button" @click="$dispatch('open-modal', 'confirm-clear-week')"
                        class="rounded-md px-3 py-2 text-sm font-medium text-red-700 hover:bg-red-50">
                    Clear week
                </button>
            @endif
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

                                {{-- FR-408. Only a scheduled shift can move: a
                                     checked-in one has somebody standing in the
                                     room, and `checked_in_at` says who, so
                                     reassigning it would put their attendance
                                     against a different name. Nor one that has
                                     ended: nobody can cover it any more.

                                     Shown as a form rather than a link because
                                     it changes data, and inline rather than on
                                     its own screen because the decision is made
                                     while looking at the week. --}}
                                @if ($shift->isReassignable() && $assistants->count() > 1)
                                    <form method="POST"
                                          action="{{ route('admin.roster.reassign', $shift) }}"
                                          class="mt-1.5">
                                        @csrf
                                        @method('PATCH')

                                        <label class="sr-only" for="reassign-{{ $shift->id }}">
                                            Reassign the {{ $shift->label() }} shift
                                        </label>

                                        <select id="reassign-{{ $shift->id }}"
                                                name="user_id"
                                                onchange="this.form.submit()"
                                                class="w-full rounded border-slate-300 py-1 text-xs text-slate-600
                                                       focus:border-brand-500 focus:ring-brand-500">
                                            <option value="">Reassign to…</option>
                                            @foreach ($assistants as $candidate)
                                                @continue($candidate->id === $shift->user_id)
                                                <option value="{{ $candidate->id }}">{{ $candidate->name }}</option>
                                            @endforeach
                                        </select>
                                    </form>
                                @endif

                                {{-- Only before it starts: from then on it is
                                     attendance, and a no-show is recorded as
                                     missed rather than erased. --}}
                                @if ($shift->isRemovable())
                                    <button type="button"
                                            @click="$dispatch('open-modal', 'confirm-remove-{{ $shift->id }}')"
                                            class="mt-1 text-xs font-medium text-red-700 hover:underline">
                                        Remove
                                    </button>

                                    <x-modal name="confirm-remove-{{ $shift->id }}" title="Remove this shift?">
                                        <p>{{ $shift->user?->name }}, {{ $shift->location?->code }}, {{ $shift->label() }}.</p>
                                        <p class="mt-2">They are told they do not need to come in, and its reminders are cancelled.</p>
                                        <x-slot:footer>
                                            <button type="button" @click="$dispatch('close-modal', 'confirm-remove-{{ $shift->id }}')"
                                                    class="rounded-md bg-white px-3 py-2 text-sm font-medium text-slate-700 ring-1 ring-slate-300">Cancel</button>
                                            <form method="POST" action="{{ route('admin.roster.destroy', $shift) }}">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit"
                                                        class="rounded-md bg-red-600 px-3 py-2 text-sm font-medium text-white hover:bg-red-700">Remove shift</button>
                                            </form>
                                        </x-slot:footer>
                                    </x-modal>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        @endforeach
    </div>

    @if ($removableCount > 0)
        <x-modal name="confirm-clear-week" title="Clear the week of {{ $week->format('j M') }}?">
            <p>
                Removes the {{ $removableCount }} {{ Str::plural('shift', $removableCount) }} that
                {{ $removableCount === 1 ? 'has' : 'have' }} not started. Each assistant is told.
            </p>
            <p class="mt-2">
                Shifts that have started or finished stay as the attendance record.
                "Generate this week" fills the week again from the templates.
            </p>
            <x-slot:footer>
                <button type="button" @click="$dispatch('close-modal', 'confirm-clear-week')"
                        class="rounded-md bg-white px-3 py-2 text-sm font-medium text-slate-700 ring-1 ring-slate-300">Cancel</button>
                <form method="POST" action="{{ route('admin.roster.clear') }}">
                    @csrf
                    @method('DELETE')
                    <input type="hidden" name="week" value="{{ $week->toDateString() }}">
                    <button type="submit"
                            class="rounded-md bg-red-600 px-3 py-2 text-sm font-medium text-white hover:bg-red-700">
                        Remove {{ $removableCount }} {{ Str::plural('shift', $removableCount) }}
                    </button>
                </form>
            </x-slot:footer>
        </x-modal>
    @endif
</x-app-layout>
