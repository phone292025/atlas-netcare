<x-app-layout>
    <x-slot name="title">My shift</x-slot>

    {{-- THE ASSISTANT'S HOME SCREEN. UserRole::homeRoute() sends them here
         after signing in, so the no-shift case is not an edge case. It is
         what most assistants see most days, and it has to say something
         useful rather than look broken. --}}

    @php
        $now = now()->campus();
        $greeting = match (true) {
            $now->hour < 12 => 'Good morning',
            $now->hour < 17 => 'Good afternoon',
            default => 'Good evening',
        };
        $firstName = \Illuminate\Support\Str::before(trim((string) auth()->user()->name).' ', ' ');
        $span = ['syntax' => \Carbon\CarbonInterface::DIFF_ABSOLUTE, 'parts' => 2];

        if ($shift) {
            $starts = $shift->startsAt();
            $ends = $shift->endsAt();

            // One line on where the shift stands right now. The button below
            // says what to do about it; this says why.
            [$when, $whenTone] = match (true) {
                $shift->status === \App\Enums\ShiftStatus::Completed => ['Finished'.($shift->checked_out_at ? ' at '.$shift->checked_out_at->campus()->format('H:i') : ''), 'muted'],
                $shift->status === \App\Enums\ShiftStatus::Missed => ['Recorded as missed', 'urgent'],
                $shift->isActive() && $ends->isFuture() => ['Ends in '.$ends->diffForHumans(now(), $span), 'good'],
                $shift->isActive() => ['Past its end time. Check out when you leave.', 'attention'],
                $starts->isFuture() => ['Starts in '.$starts->diffForHumans(now(), $span), 'muted'],
                $ends->isFuture() => ['Started '.$starts->diffForHumans(now(), $span).' ago and you have not checked in', 'attention'],
                default => ['This shift has ended', 'muted'],
            };

            // How far through the shift we are, for the bar. Only while it
            // still means something.
            $live = in_array($shift->status, [\App\Enums\ShiftStatus::Scheduled, \App\Enums\ShiftStatus::CheckedIn], true) && $shift->isToday();
            $length = max(1, $starts->diffInSeconds($ends));
            $progress = (int) round(max(0, min(1, $starts->diffInSeconds(now(), false) / $length)) * 100);
        }
    @endphp

    <x-page-header :title="$greeting.($firstName !== '' ? ', '.$firstName : '')"
                   :subtitle="$now->format('l j F Y')" />

    <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_22rem]">
        <div class="min-w-0 space-y-6">
            @if (! $shift)
                {{-- Most days for most assistants, so one line that says what
                     comes next, not a box the size of a shift. --}}
                @php($nextShift = $upcoming->first())
                <section class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-line bg-surface p-4 shadow-xs">
                    <p class="flex min-w-0 items-start gap-3 text-sm text-fg-muted">
                        <x-icon name="calendar" class="mt-0.5 size-5 flex-none text-fg-subtle" />
                        <span>
                            <span class="font-medium text-fg">Nothing rostered today.</span>
                            @if ($nextShift)
                                Your next shift is {{ $nextShift->shift_date->format('l j F') }},
                                {{ substr($nextShift->start_time, 0, 5) }} in {{ $nextShift->location?->name ?? 'a room not set yet' }}.
                            @else
                                Nothing is on your roster yet.
                            @endif
                        </span>
                    </p>
                    <x-button variant="secondary" size="sm" :href="route('assistant.shifts.index')">My week</x-button>
                </section>
            @else
                {{-- Today's shift. The one thing on this screen that needs doing
                     is on it, as the biggest button. --}}
                <section class="overflow-hidden rounded-xl border border-line bg-surface shadow-xs">
                    <div class="p-5 sm:p-6">
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <p class="kicker">Today's shift</p>
                            <x-status-badge :status="$shift->status" />
                        </div>

                        <div class="mt-4 flex flex-wrap items-end gap-x-6 gap-y-2">
                            <p class="text-[36px] font-semibold leading-none tracking-tight text-fg tabular-nums sm:text-[44px]">
                                {{ substr($shift->start_time, 0, 5) }}<span class="px-1 font-normal text-fg-subtle">-</span>{{ substr($shift->end_time, 0, 5) }}
                            </p>
                            <p @class([
                                'pb-1 text-sm font-medium',
                                'text-fg-muted' => $whenTone === 'muted',
                                'text-emerald-700' => $whenTone === 'good',
                                'text-amber-700' => $whenTone === 'attention',
                                'text-red-700' => $whenTone === 'urgent',
                            ])>{{ $when }}</p>
                        </div>

                        <p class="mt-3 flex items-center gap-2 text-[15px] text-fg">
                            <x-icon name="map-pin" class="size-4 flex-none text-fg-subtle" />
                            <span class="truncate">{{ $shift->location?->label() ?? 'No location' }}</span>
                        </p>

                        @if ($live)
                            <div class="mt-5" aria-hidden="true">
                                <div class="h-1.5 overflow-hidden rounded-full bg-sunken">
                                    <div @class([
                                            'h-full rounded-full transition-[width] duration-500',
                                            'bg-emerald-500' => $shift->isActive(),
                                            'bg-brand-500' => ! $shift->isActive(),
                                         ])
                                         style="width: {{ $progress }}%"></div>
                                </div>
                                <div class="mt-1.5 flex justify-between font-mono text-2xs text-fg-subtle">
                                    <span>{{ substr($shift->start_time, 0, 5) }}</span>
                                    <span>{{ substr($shift->end_time, 0, 5) }}</span>
                                </div>
                            </div>
                        @endif

                        @if ($shift->checked_in_at)
                            <p class="mt-4 flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-fg-muted">
                                <span class="inline-flex items-center gap-1.5">
                                    <x-icon name="login" class="size-4 text-emerald-600" />
                                    Checked in at {{ $shift->checked_in_at->campus()->format('H:i') }}.
                                </span>
                                @if ($shift->checked_out_at)
                                    <span class="inline-flex items-center gap-1.5">
                                        <x-icon name="logout" class="size-4 text-fg-subtle" />
                                        Checked out at {{ $shift->checked_out_at->campus()->format('H:i') }}.
                                    </span>
                                @endif
                            </p>
                        @endif
                    </div>

                    <div class="flex flex-col gap-3 border-t border-line bg-sunken/50 px-5 py-4 sm:flex-row sm:items-center sm:px-6">
                        @if ($shift->status === \App\Enums\ShiftStatus::Scheduled)
                            <form method="POST" action="{{ route('assistant.shifts.check-in', $shift) }}"
                                  class="flex flex-col gap-2 sm:flex-row sm:items-center sm:gap-3">
                                @csrf
                                <x-button type="submit" size="lg" icon="login" class="w-full sm:w-auto"
                                          :disabled="! $shift->canCheckInNow()">
                                    Check in
                                </x-button>
                                @unless ($shift->canCheckInNow())
                                    {{-- Say why it is disabled, and say the right
                                         thing: "opens at 08:45" is helpful before
                                         the shift and a lie after it. A greyed-out
                                         button with no explanation, or the wrong
                                         one, is what people raise a ticket about. --}}
                                    <span class="text-center text-xs text-fg-muted sm:text-left">
                                        @if ($shift->endsAt()->isPast())
                                            This shift has ended.
                                        @else
                                            Opens {{ $shift->startsAt()->subMinutes(config('atlas.shifts.check_in_grace_min'))->format('H:i') }}
                                        @endif
                                    </span>
                                @endunless
                            </form>
                        @elseif ($shift->isActive())
                            <x-button size="lg" icon="logout" class="w-full sm:w-auto"
                                      @click="$dispatch('open-modal', 'check-out')">
                                Check out
                            </x-button>
                        @else
                            <span class="text-sm text-fg-muted">This shift is finished.</span>
                        @endif

                        {{-- One shift, downloaded as a file. Kept for the case where
                             somebody has not subscribed and wants this shift on
                             their phone right now, but subscribing is the better
                             path: it downloads nothing, ever. --}}
                        <x-button variant="ghost" icon="calendar" class="sm:ml-auto"
                                  :href="route('assistant.shifts.calendar', $shift)"
                                  title="Downloads a single event. To stop downloading files, subscribe instead.">
                            Add just this shift
                        </x-button>
                    </div>
                </section>
            @endif

            @if ($handover)
                {{-- Shown on arrival, not only sent as an alarm: an assistant who
                     was not on shift when the alarm fired would otherwise never
                     see it. --}}
                <section class="rounded-xl border border-amber-200 bg-amber-50/60 p-5 shadow-xs">
                    <div class="flex items-start gap-3">
                        <x-avatar :name="$handover->user?->name" size="md" />
                        <div class="min-w-0 flex-1">
                            <h3 class="text-[15px] font-semibold text-fg">Handover from the last shift</h3>
                            <p class="text-xs text-fg-muted">
                                {{ $handover->user?->name }} · {{ $handover->checked_out_at?->diffForHumans() }}
                            </p>
                            <p class="mt-3 whitespace-pre-line text-sm leading-relaxed text-fg">{{ $handover->handover_notes }}</p>
                        </div>
                    </div>
                </section>
            @endif

            @if ($shift && ($shift->isActive() || $shift->status === \App\Enums\ShiftStatus::Scheduled))
                <section aria-labelledby="while-here">
                    <h3 id="while-here" class="kicker mb-3">While you are here</h3>
                    <div class="grid gap-3 sm:grid-cols-3">
                        @foreach ([
                            ['route' => 'assistant.inspections.index', 'icon' => 'list-checks', 'title' => 'Start an inspection round', 'hint' => 'Walk a lab against its checklist'],
                            ['route' => 'assistant.tickets.create', 'icon' => 'ticket', 'title' => 'Raise a ticket', 'hint' => 'Report something that is broken'],
                            ['route' => 'assistant.aps.index', 'icon' => 'wifi', 'title' => 'Check AP status', 'hint' => 'See which access points are down'],
                        ] as $action)
                            <a href="{{ route($action['route']) }}"
                               class="group flex items-center gap-3 rounded-xl border border-line bg-surface p-4 shadow-xs transition
                                      hover:border-line-strong hover:shadow-sm sm:flex-col sm:items-start">
                                <span class="grid size-10 flex-none place-items-center rounded-lg bg-brand-50 text-brand-700">
                                    <x-icon :name="$action['icon']" class="size-5" />
                                </span>
                                <span class="min-w-0 flex-1">
                                    <span class="block text-sm font-medium text-fg">{{ $action['title'] }}</span>
                                    <span class="block text-xs text-fg-muted">{{ $action['hint'] }}</span>
                                </span>
                                <x-icon name="chevron-right" class="size-4 flex-none text-fg-subtle transition group-hover:translate-x-0.5 sm:hidden" />
                            </a>
                        @endforeach
                    </div>
                </section>
            @endif

            {{-- The work of a shift, and worth seeing on a day without one: how
                 far each room's round has got, with this shift's room marked. --}}
            @if ($rooms->isNotEmpty())
                <section aria-labelledby="rounds-today">
                    <div class="mb-3 flex items-baseline justify-between gap-2">
                        <h3 id="rounds-today" class="text-[15px] font-semibold text-fg">Rounds today</h3>
                        <a href="{{ route('assistant.inspections.index') }}" class="link text-sm">All rounds</a>
                    </div>
                    <x-room-rounds :rooms="$rooms" :highlight="$shift?->location_id" :columns="2" />
                </section>
            @endif
        </div>

        <div class="min-w-0 space-y-6">
            <x-card title="Coming up" flush>
                <x-slot:header>
                    <a href="{{ route('assistant.shifts.index') }}" class="link text-sm">My week</a>
                </x-slot:header>

                @if ($upcoming->isEmpty())
                    <p class="px-5 py-4 text-sm text-fg-muted">
                        Nothing scheduled. An administrator generates shifts from the weekly roster.
                    </p>
                @else
                    <ul class="divide-y divide-line">
                        @foreach ($upcoming as $next)
                            <li class="flex items-center gap-3 px-5 py-3">
                                <div class="grid w-11 flex-none place-items-center rounded-lg border border-line bg-sunken py-1 text-center leading-none">
                                    <span class="text-2xs font-medium uppercase text-fg-muted">{{ $next->shift_date->format('D') }}</span>
                                    <span class="mt-0.5 font-mono text-base font-medium text-fg">{{ $next->shift_date->format('j') }}</span>
                                </div>
                                <div class="min-w-0 flex-1">
                                    <p class="font-mono text-sm text-fg">
                                        {{ substr($next->start_time, 0, 5) }}-{{ substr($next->end_time, 0, 5) }}
                                    </p>
                                    <p class="truncate text-xs text-fg-muted">{{ $next->location?->label() }}</p>
                                </div>
                                <x-status-badge :status="$next->status" class="flex-none" />
                            </li>
                        @endforeach
                    </ul>
                    <x-slot:footer>
                        <a href="{{ route('assistant.shifts.index') }}" class="link inline-flex items-center gap-1">
                            See the whole week <x-icon name="arrow-right" class="size-3.5" />
                        </a>
                    </x-slot:footer>
                @endif
            </x-card>

            {{-- Subscribe once and every future shift arrives on its own, including
                 weeks an administrator has not generated yet.

                 HIDDEN UNLESS ATLAS_CALENDAR_SUBSCRIPTION IS ON. Subscribing needs
                 a URL the calendar application can fetch, and a development server
                 cannot be one: the new Outlook takes only https:// and refuses a
                 private address, and Google fetches from its own servers which
                 cannot see a machine on your Wi-Fi. Only Apple's clients accept a
                 plain http:// feed on a local network.

                 The feed works and is tested. Turn the flag on once this is
                 deployed behind HTTPS. Until then a control that fails for two of
                 the three major clients is worse than no control.
                 See config/atlas.php for the full reasoning. --}}
            @if (config('atlas.calendar.subscription'))
                <x-card title="Put your roster in your phone"
                        subtitle="Subscribe once. New shifts appear on their own, and your phone reminds you.">
                    @if (auth()->user()->calendar_token)
                        @php($feed = route('shifts.calendar.feed', auth()->user()->calendar_token))
                        @php($webcal = preg_replace('#^https?://#', 'webcal://', $feed))

                        {{-- webcal:// is a protocol calendar applications register for, so
                             clicking it opens Outlook or Calendar directly and offers to
                             subscribe. No download, and nothing left in the Downloads
                             folder. --}}
                        <x-button :href="$webcal" icon="calendar" class="w-full">Open in my calendar app</x-button>

                        <p class="mt-3 text-sm text-fg-muted">
                            That opens your calendar and asks whether to subscribe. Say yes once and
                            every shift arrives on its own, including weeks that have not been rostered yet.
                        </p>

                        <details class="mt-3 text-sm text-fg-muted">
                            <summary class="cursor-pointer font-medium text-fg">If nothing happens</summary>
                            <p class="mt-2">
                                Copy the link instead.
                                On iPhone go to Settings, Calendar, Accounts, Add Account, Other, Add Subscribed Calendar.
                                On Windows open Outlook, Add calendar, Subscribe from web.
                                On Android open Google Calendar on a computer, then Other calendars, From URL.
                            </p>
                        </details>

                        <div x-data="{ copied: false }" class="mt-3 flex items-center gap-2">
                            <input type="text" readonly value="{{ $feed }}" aria-label="Subscription link"
                                   x-ref="feed" @focus="$refs.feed.select()"
                                   class="h-9 min-w-0 flex-1 bg-sunken font-mono text-xs">
                            <button type="button" class="btn btn-secondary btn-sm flex-none"
                                    @click="navigator.clipboard.writeText($refs.feed.value); copied = true; setTimeout(() => copied = false, 2000)">
                                <x-icon name="copy" class="size-3.5" />
                                <span x-show="! copied">Copy</span>
                                <span x-show="copied" x-cloak class="text-emerald-700">Copied</span>
                            </button>
                        </div>

                        <p class="mt-3 text-xs text-fg-subtle">
                            Anyone with this link can see your shift times and rooms, so keep it to yourself.
                            Replacing it stops the old one working immediately.
                        </p>

                        <x-slot:footer>
                            <div class="flex flex-wrap gap-2">
                                <form method="POST" action="{{ route('assistant.shifts.calendar.link') }}">
                                    @csrf
                                    <x-button type="submit" variant="secondary" size="sm" icon="refresh">Replace link</x-button>
                                </form>
                                <form method="POST" action="{{ route('assistant.shifts.calendar.revoke') }}">
                                    @csrf
                                    @method('DELETE')
                                    <x-button type="submit" variant="ghost" size="sm" class="text-red-700 hover:bg-red-50">Turn off</x-button>
                                </form>
                            </div>
                        </x-slot:footer>
                    @else
                        <p class="text-sm text-fg-muted">
                            Adding shifts one at a time downloads a file each time. Subscribing instead lets your
                            calendar read them directly, so nothing is downloaded and new shifts appear on their own.
                        </p>

                        <x-slot:footer>
                            <form method="POST" action="{{ route('assistant.shifts.calendar.link') }}">
                                @csrf
                                <x-button type="submit" size="sm">Create my subscription link</x-button>
                            </form>
                        </x-slot:footer>
                    @endif
                </x-card>
            @endif
        </div>
    </div>

    @if ($shift?->isActive())
        <x-modal name="check-out" title="Check out of this shift?">
            <form method="POST" action="{{ route('assistant.shifts.check-out', $shift) }}" id="check-out-form">
                @csrf
                <x-form.textarea name="handover_notes" label="Anything the next shift should know?" rows="4"
                                 help="Optional, but this is how a problem gets picked up rather than rediscovered." />
            </form>

            <x-slot:footer>
                <x-button variant="secondary" @click="$dispatch('close-modal', 'check-out')">Cancel</x-button>
                <x-button type="submit" form="check-out-form" icon="logout">Check out</x-button>
            </x-slot:footer>
        </x-modal>
    @endif
</x-app-layout>
