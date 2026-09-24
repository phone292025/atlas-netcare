<x-app-layout>
    <x-slot name="title">Roster</x-slot>

    @php
        $end = $week->copy()->endOfWeek();
        $isThisWeek = $week->isSameDay(\App\Support\CampusClock::now()->startOfWeek());
    @endphp

    <x-page-header title="Roster"
                   :subtitle="$shiftCount.' '.Str::plural('shift', $shiftCount).' this week. Generated from the shift templates, then adjusted here.'">
        <x-slot:actions>
            @unless ($isThisWeek)
                <x-button variant="ghost" size="sm" :href="route('admin.roster.index')">This week</x-button>
            @endunless

            <div class="inline-flex items-center rounded-lg border border-line bg-surface shadow-xs">
                <a href="{{ route('admin.roster.index', ['week' => $week->copy()->subWeek()->toDateString()]) }}"
                   class="grid size-9 place-items-center rounded-l-lg text-fg-muted hover:bg-sunken hover:text-fg" aria-label="Previous week">
                    <x-icon name="chevron-left" class="size-4" />
                </a>
                <span class="border-x border-line px-3 text-sm font-medium tabular-nums text-fg">
                    {{ $week->format('j M') }} to {{ $end->format('j M Y') }}
                </span>
                <a href="{{ route('admin.roster.index', ['week' => $week->copy()->addWeek()->toDateString()]) }}"
                   class="grid size-9 place-items-center rounded-r-lg text-fg-muted hover:bg-sunken hover:text-fg" aria-label="Next week">
                    <x-icon name="chevron-right" class="size-4" />
                </a>
            </div>

            @if ($removableCount > 0)
                <x-button variant="ghost" class="text-red-700 hover:bg-red-50" icon="trash"
                          @click="$dispatch('open-modal', 'confirm-clear-week')">
                    Clear week
                </x-button>
            @endif

            {{-- Safe to press twice: the service is idempotent, because the
                 obvious reaction to a slow page is to press the button again. --}}
            <form method="POST" action="{{ route('admin.roster.generate') }}">
                @csrf
                <input type="hidden" name="week" value="{{ $week->toDateString() }}">
                <x-button type="submit" icon="lightning" :disabled="$templateCount === 0">Generate this week</x-button>
            </form>
        </x-slot:actions>
    </x-page-header>

    @if ($templateCount === 0)
        <x-alert type="warning" class="mb-6" title="No active shift templates">
            The generator builds a week from the weekly patterns, so there is nothing for it to do yet.
            <a href="{{ route('admin.shift-templates.create') }}" class="font-medium underline">Add a template</a> first.
        </x-alert>
    @endif

    {{-- Seven columns on a wide screen, fewer as it narrows, one on a phone. --}}
    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4 2xl:grid-cols-7">
        @foreach ($days as $day)
            @php
                $shifts = $byDay->get($day->toDateString(), collect());
                $today = $day->isToday();
            @endphp

            <section @class([
                'flex flex-col rounded-xl border bg-surface shadow-xs',
                'border-brand-600 ring-1 ring-brand-600' => $today,
                'border-line' => ! $today,
            ])>
                <header class="flex items-center justify-between gap-2 border-b border-line px-3.5 py-2.5">
                    <span class="flex items-baseline gap-1.5">
                        <span @class(['text-sm font-semibold', 'text-brand-700' => $today, 'text-fg' => ! $today])>{{ $day->format('D') }}</span>
                        <span class="text-sm tabular-nums text-fg-muted">{{ $day->format('j M') }}</span>
                    </span>
                    @if ($today)
                        <x-badge tone="neutral">Today</x-badge>
                    @elseif ($shifts->isNotEmpty())
                        <span class="text-xs tabular-nums text-fg-subtle">{{ $shifts->count() }}</span>
                    @endif
                </header>

                @if ($shifts->isEmpty())
                    <p class="px-3.5 py-6 text-center text-sm text-fg-subtle">Nobody on</p>
                @else
                    <ul class="space-y-2 p-2.5">
                        @foreach ($shifts as $shift)
                            <li class="rounded-lg border border-line bg-canvas/60 p-2.5">
                                <div class="flex items-start gap-2.5">
                                    <x-avatar :name="$shift->user?->name" size="md" />
                                    <div class="min-w-0 flex-1">
                                        <p class="truncate text-sm font-medium text-fg">{{ $shift->user?->name }}</p>
                                        <p class="truncate text-xs tabular-nums text-fg-muted">
                                            {{ substr($shift->start_time, 0, 5) }}-{{ substr($shift->end_time, 0, 5) }} · {{ $shift->location?->code }}
                                        </p>
                                    </div>
                                </div>

                                <div class="mt-2 flex items-center justify-between gap-2">
                                    <x-status-badge :status="$shift->status" />

                                    {{-- Only before it starts: from then on it is
                                         attendance, and a no-show is recorded as
                                         missed rather than erased. --}}
                                    @if ($shift->isRemovable())
                                        <button type="button"
                                                @click="$dispatch('open-modal', 'confirm-remove-{{ $shift->id }}')"
                                                class="btn-icon size-7 text-fg-subtle hover:bg-red-50 hover:text-red-700"
                                                aria-label="Remove {{ $shift->user?->name }}'s shift on {{ $day->format('D j M') }}">
                                            <x-icon name="trash" class="size-3.5" />
                                        </button>
                                    @endif
                                </div>

                                {{-- FR-408. Only a scheduled shift can move: a
                                     checked-in one has somebody standing in the
                                     room, and `checked_in_at` says who, so
                                     reassigning it would put their attendance
                                     against a different name. Nor one that has
                                     ended: nobody can cover it any more.

                                     A form rather than a link because it changes
                                     data, and inline rather than on its own
                                     screen because the decision is made while
                                     looking at the week. --}}
                                @if ($shift->isReassignable() && $assistants->count() > 1)
                                    <form method="POST" action="{{ route('admin.roster.reassign', $shift) }}" class="mt-2">
                                        @csrf
                                        @method('PATCH')

                                        <label class="sr-only" for="reassign-{{ $shift->id }}">
                                            Reassign the {{ $shift->label() }} shift
                                        </label>

                                        <select id="reassign-{{ $shift->id }}" name="user_id" onchange="this.form.submit()"
                                                class="h-8 w-full py-0 pl-2.5 pr-7 text-xs text-fg-muted">
                                            <option value="">Hand to somebody else</option>
                                            @foreach ($assistants as $candidate)
                                                @continue($candidate->id === $shift->user_id)
                                                <option value="{{ $candidate->id }}">{{ $candidate->name }}</option>
                                            @endforeach
                                        </select>
                                    </form>
                                @endif

                                @if ($shift->isRemovable())
                                    <x-modal name="confirm-remove-{{ $shift->id }}" title="Remove this shift?">
                                        <p>{{ $shift->user?->name }}, {{ $shift->location?->code }}, {{ $shift->label() }}.</p>
                                        <p class="mt-2">They are told they do not need to come in, and its reminders are cancelled.</p>
                                        <x-slot:footer>
                                            <x-button variant="secondary" @click="$dispatch('close-modal', 'confirm-remove-{{ $shift->id }}')">Cancel</x-button>
                                            <form method="POST" action="{{ route('admin.roster.destroy', $shift) }}">
                                                @csrf
                                                @method('DELETE')
                                                <x-button type="submit" variant="danger">Remove shift</x-button>
                                            </form>
                                        </x-slot:footer>
                                    </x-modal>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
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
                <x-button variant="secondary" @click="$dispatch('close-modal', 'confirm-clear-week')">Cancel</x-button>
                <form method="POST" action="{{ route('admin.roster.clear') }}">
                    @csrf
                    @method('DELETE')
                    <input type="hidden" name="week" value="{{ $week->toDateString() }}">
                    <x-button type="submit" variant="danger">
                        Remove {{ $removableCount }} {{ Str::plural('shift', $removableCount) }}
                    </x-button>
                </form>
            </x-slot:footer>
        </x-modal>
    @endif
</x-app-layout>
