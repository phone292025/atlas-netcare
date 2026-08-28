<x-app-layout>
    <x-slot name="title">My shift</x-slot>

    {{-- THE ASSISTANT'S HOME SCREEN. UserRole::homeRoute() sends them here
         after signing in, so the no-shift case is not an edge case — it is
         what most assistants see most days, and it has to say something
         useful rather than look broken. --}}

    <div class="mx-auto max-w-3xl">
        <x-page-header title="My shift"
                       :subtitle="now()->format('l j F Y')" />

        @if (! $shift)
            <x-card class="mb-4">
                <x-empty-state title="Nothing rostered today"
                               message="You have no shift scheduled for today. Anything coming up is listed below."
                               icon="calendar" />
            </x-card>
        @else
            <x-card class="mb-4">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="text-xs font-medium uppercase tracking-wide text-slate-500">
                            {{ substr($shift->start_time, 0, 5) }} – {{ substr($shift->end_time, 0, 5) }}
                        </p>
                        <h3 class="mt-0.5 text-lg font-semibold text-slate-900">
                            {{ $shift->location?->label() ?? 'No location' }}
                        </h3>
                    </div>
                    <x-status-badge :status="$shift->status" />
                </div>

                @if ($shift->checked_in_at)
                    <p class="mt-3 text-sm text-slate-600">
                        Checked in at {{ $shift->checked_in_at->format('H:i') }}.
                        @if ($shift->checked_out_at)
                            Checked out at {{ $shift->checked_out_at->format('H:i') }}.
                        @endif
                    </p>
                @endif

                <x-slot:footer>
                    @if ($shift->status === \App\Enums\ShiftStatus::Scheduled)
                        <form method="POST" action="{{ route('assistant.shifts.check-in', $shift) }}">
                            @csrf
                            <button type="submit" @disabled(! $shift->canCheckInNow())
                                    class="w-full rounded-md bg-brand-600 px-4 py-3 text-sm font-semibold text-white
                                           hover:bg-brand-700 disabled:cursor-not-allowed disabled:opacity-50 sm:w-auto">
                                Check in
                            </button>
                            @unless ($shift->canCheckInNow())
                                {{-- Say why it is disabled, and say the right
                                     thing: "opens at 08:45" is helpful before
                                     the shift and a lie after it. A greyed-out
                                     button with no explanation — or the wrong
                                     one — is what people raise a ticket
                                     about. --}}
                                <span class="ml-2 text-xs text-slate-500">
                                    @if ($shift->endsAt()->isPast())
                                        This shift has ended.
                                    @else
                                        Opens {{ $shift->startsAt()->subMinutes(config('atlas.shifts.check_in_grace_min'))->format('H:i') }}
                                    @endif
                                </span>
                            @endunless
                        </form>
                    @elseif ($shift->isActive())
                        <button type="button" @click="$dispatch('open-modal', 'check-out')"
                                class="w-full rounded-md bg-brand-600 px-4 py-3 text-sm font-semibold text-white hover:bg-brand-700 sm:w-auto">
                            Check out
                        </button>
                    @else
                        <span class="text-sm text-slate-500">This shift is finished.</span>
                    @endif
                </x-slot:footer>
            </x-card>

            @if ($shift->isActive() || $shift->status === \App\Enums\ShiftStatus::Scheduled)
                <x-card title="While you are here" class="mb-4">
                    <div class="flex flex-wrap gap-2">
                        <a href="{{ route('assistant.inspections.index') }}"
                           class="rounded-md bg-white px-3 py-2 text-sm font-medium text-slate-700 ring-1 ring-slate-300 hover:bg-slate-50">
                            Start an inspection round
                        </a>
                        <a href="{{ route('assistant.tickets.create') }}"
                           class="rounded-md bg-white px-3 py-2 text-sm font-medium text-slate-700 ring-1 ring-slate-300 hover:bg-slate-50">
                            Raise a ticket
                        </a>
                        <a href="{{ route('assistant.aps.index') }}"
                           class="rounded-md bg-white px-3 py-2 text-sm font-medium text-slate-700 ring-1 ring-slate-300 hover:bg-slate-50">
                            Check AP status
                        </a>
                    </div>
                </x-card>
            @endif
        @endif

        @if ($handover)
            {{-- Shown on arrival, not only sent as an alarm: an assistant who
                 was not on shift when the alarm fired would otherwise never
                 see it. --}}
            <x-card title="Handover from the last shift"
                    :subtitle="$handover->user?->name.' · '.$handover->checked_out_at?->diffForHumans()"
                    class="mb-4">
                <p class="whitespace-pre-line text-sm text-slate-700">{{ $handover->handover_notes }}</p>
            </x-card>
        @endif

        <x-card title="Coming up" subtitle="Your next shifts." flush>
            @if ($upcoming->isEmpty())
                <x-empty-state title="Nothing scheduled"
                               message="Shifts are generated from the weekly roster by an administrator."
                               icon="calendar" />
            @else
                <ul class="divide-y divide-slate-100">
                    @foreach ($upcoming as $next)
                        <li class="flex items-center justify-between gap-3 px-6 py-3">
                            <div class="min-w-0">
                                <p class="text-sm font-medium text-slate-900">{{ $next->label() }}</p>
                                <p class="text-xs text-slate-500">{{ $next->location?->label() }}</p>
                            </div>
                            <x-status-badge :status="$next->status" class="flex-none" />
                        </li>
                    @endforeach
                </ul>

                <div class="border-t border-slate-200 px-6 py-3">
                    <a href="{{ route('assistant.shifts.index') }}" class="text-sm text-brand-700 hover:underline">
                        See the whole week
                    </a>
                </div>
            @endif
        </x-card>
    </div>

    @if ($shift?->isActive())
        <x-modal name="check-out" title="Check out of this shift?">
            <form method="POST" action="{{ route('assistant.shifts.check-out', $shift) }}" id="check-out-form">
                @csrf
                <x-form.textarea name="handover_notes" label="Anything the next shift should know?" rows="4"
                                 help="Optional, but this is how a problem gets picked up rather than rediscovered." />
            </form>
            <x-slot:footer>
                <button type="button" @click="$dispatch('close-modal', 'check-out')"
                        class="rounded-md bg-white px-3 py-2 text-sm font-medium text-slate-700 ring-1 ring-slate-300">Cancel</button>
                <button type="submit" form="check-out-form"
                        class="rounded-md bg-brand-600 px-3 py-2 text-sm font-medium text-white hover:bg-brand-700">Check out</button>
            </x-slot:footer>
        </x-modal>
    @endif
</x-app-layout>
