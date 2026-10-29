<x-app-layout>
    <x-slot name="title">Dashboard</x-slot>

    {{--
        The engineer's first screen. Three questions, in the order an engineer
        asks them at the start of a day: what is mine, what is waiting for
        somebody, and is the network all right.

        The access point panel polls the same endpoint as the full list
        (ADR-0005), so the two never disagree.
    --}}

    @php
        $now = now()->campus();
        $greeting = match (true) {
            $now->hour < 12 => 'Good morning',
            $now->hour < 17 => 'Good afternoon',
            default => 'Good evening',
        };
        $firstName = \Illuminate\Support\Str::before(trim((string) auth()->user()->name).' ', ' ');
        $tones = collect(\App\Support\Tone::all())->mapWithKeys(fn ($tone) => [$tone => [
            'badge' => \App\Support\Tone::badge($tone),
            'dot' => \App\Support\Tone::solid($tone),
        ]]);
    @endphp

    <x-page-header :title="$greeting.($firstName !== '' ? ', '.$firstName : '')"
                   :subtitle="$now->format('l j F Y').'. '.$counts['open'].' open '.Str::plural('ticket', $counts['open']).' across campus.'">
        <x-slot:actions>
            <x-button variant="secondary" icon="ticket" :href="route('engineer.tickets.index')">Ticket queue</x-button>
            <x-button icon="plus" :href="route('assistant.tickets.create')">Raise ticket</x-button>
        </x-slot:actions>
    </x-page-header>

    <div class="mb-6 grid grid-cols-2 gap-3 lg:grid-cols-4">
        <x-stat-tile label="Assigned to you" :value="$counts['mine']" icon="user"
                     :href="route('engineer.tickets.index', ['mine' => 1])" />
        <x-stat-tile label="Past due" :value="$counts['breaching']" icon="clock"
                     :tone="$counts['breaching'] > 0 ? 'urgent' : null"
                     :delta="$counts['breaching'] > 0 ? 'Past their target' : null"
                     :href="route('engineer.tickets.index', ['breaching' => 1])" />
        <x-stat-tile label="Waiting for somebody" :value="$counts['unassigned']" icon="inbox"
                     :href="route('engineer.tickets.index', ['unassigned' => 1])" />
        <x-stat-tile label="You resolved this week" :value="$counts['resolvedThisWeek']" icon="check-circle"
                     :tone="$counts['resolvedThisWeek'] > 0 ? 'healthy' : null" />
    </div>

    {{-- Side by side only from xl. Below it, next to the sidebar, the ticket
         column was too narrow for a title. --}}
    <div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_24rem]">
        <div class="min-w-0 space-y-6">
            <x-card title="Your queue" subtitle="Most urgent first, then whatever is due soonest." flush>
                <x-slot:header>
                    <a href="{{ route('engineer.tickets.index', ['mine' => 1]) }}" class="link text-sm">All of yours</a>
                </x-slot:header>

                @if ($mine->isEmpty())
                    <x-empty-state title="Nothing assigned to you" icon="check-circle"
                                   message="Pick something up from the tickets waiting below, or from the queue.">
                        @if ($counts['unassigned'] > 0)
                            <x-slot:action>
                                <x-button variant="secondary" :href="route('engineer.tickets.index', ['unassigned' => 1])">
                                    See what is waiting
                                </x-button>
                            </x-slot:action>
                        @endif
                    </x-empty-state>
                @else
                    <ul class="divide-y divide-line">
                        @foreach ($mine as $ticket)
                            <li>
                                <a href="{{ route('engineer.tickets.show', $ticket) }}"
                                   class="group flex items-center gap-4 px-5 py-3.5 transition hover:bg-sunken/60">
                                    <x-priority-pill :priority="$ticket->priority" class="w-20 flex-none" />
                                    {{-- The target line goes under the text, not in a
                                         column of its own, so the title keeps the width. --}}
                                    <span class="min-w-0 flex-1">
                                        <span class="block truncate text-sm font-medium text-fg">{{ $ticket->title }}</span>
                                        <span class="block truncate text-xs text-fg-muted">
                                            <span class="font-mono">{{ $ticket->ticket_number }}</span>
                                            · {{ $ticket->subjectLabel() }}{{ $ticket->location && $ticket->location->code !== $ticket->subjectLabel() ? ' · '.$ticket->location->code : '' }}
                                        </span>
                                        <x-sla-line :ticket="$ticket" class="mt-2" />
                                    </span>
                                    <span class="hidden flex-none sm:block">
                                        <x-status-badge :status="$ticket->status" />
                                    </span>
                                    <x-icon name="chevron-right" class="size-4 flex-none text-fg-subtle transition group-hover:translate-x-0.5" />
                                </a>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-card>

            <x-card title="Waiting for somebody" subtitle="Nobody has picked these up yet." flush>
                <x-slot:header>
                    <a href="{{ route('engineer.tickets.index', ['unassigned' => 1]) }}" class="link text-sm">All unassigned</a>
                </x-slot:header>

                @if ($waiting->isEmpty())
                    <x-empty-state title="Nothing waiting" icon="inbox"
                                   message="Every open ticket has an engineer on it." />
                @else
                    <ul class="divide-y divide-line">
                        @foreach ($waiting as $ticket)
                            <li class="flex items-center gap-4 px-5 py-3.5">
                                <x-priority-pill :priority="$ticket->priority" class="w-20 flex-none" />
                                <span class="min-w-0 flex-1">
                                    <a href="{{ route('engineer.tickets.show', $ticket) }}" class="group block">
                                        <span class="block truncate text-sm font-medium text-fg group-hover:text-brand-700">{{ $ticket->title }}</span>
                                        <span class="block truncate text-xs text-fg-muted">
                                            <span class="font-mono">{{ $ticket->ticket_number }}</span>
                                            · {{ $ticket->subjectLabel() }}
                                        </span>
                                    </a>
                                    <x-sla-line :ticket="$ticket" class="mt-2" />
                                </span>
                                {{-- One tap to take it, rather than open, find the
                                     assignee box, pick yourself and save. --}}
                                <form method="POST" action="{{ route('tickets.assign', $ticket) }}" class="flex-none">
                                    @csrf
                                    @method('PATCH')
                                    <input type="hidden" name="assigned_to" value="{{ auth()->id() }}">
                                    <x-button type="submit" variant="secondary" size="sm">Take it</x-button>
                                </form>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-card>
        </div>

        <div class="min-w-0">
            <div x-data="apSummary(@js($data), {{ $pollInterval }}, @js($tones))" x-init="start()">
                <x-card title="Access point health" flush>
                    <x-slot:header>
                        <span class="inline-flex items-center gap-1.5 text-xs text-fg-muted">
                            <span class="size-1.5 rounded-full" :class="data.stale ? 'bg-amber-500' : 'bg-emerald-500'"></span>
                            <span x-text="data.stale ? 'Collector stopped' : 'Live'">Live</span>
                        </span>
                    </x-slot:header>

                    @php
                        $healthyNow = collect($data['access_points'])
                            ->filter(fn ($ap) => $ap['status'] === 'online' && ! $ap['over_threshold'])->count();
                    @endphp
                    <div class="px-5 pt-4">
                        <div class="flex items-baseline gap-1.5">
                            <span class="text-[28px] font-semibold leading-none tracking-tight tabular-nums text-fg" x-text="count('ok')">{{ $healthyNow }}</span>
                            <span class="text-sm text-fg-muted">of <span x-text="data.counts.total">{{ $data['counts']['total'] }}</span> healthy</span>
                        </div>

                        {{-- Every access point where it is: one square each, room
                             by room, so trouble shows up in its place. Amber is
                             degraded, or a latest reading over the limits (slow, or
                             nearly full) that the status has not caught up with. --}}
                        <ul class="mt-4 space-y-2.5">
                            <template x-for="room in rooms" :key="room.code">
                                <li class="flex items-center justify-between gap-3">
                                    <span class="min-w-0 truncate text-sm text-fg-muted" x-text="room.name"></span>
                                    <span class="flex flex-none gap-1.5">
                                        <template x-for="ap in room.aps" :key="ap.id">
                                            <a :href="'{{ url('engineer/aps') }}/' + ap.id" :title="ap.code + ': ' + label(ap)"
                                               class="block size-4 rounded transition hover:scale-110"
                                               :class="{
                                                   'bg-emerald-500': health(ap) === 'ok',
                                                   'bg-amber-500': health(ap) === 'slow',
                                                   'bg-red-500': health(ap) === 'down',
                                                   'bg-slate-400': health(ap) === 'maintenance',
                                                   'border-2 border-dashed border-slate-400': health(ap) === 'unknown',
                                               }">
                                                <span class="sr-only" x-text="ap.code + ': ' + label(ap)"></span>
                                            </a>
                                        </template>
                                    </span>
                                </li>
                            </template>
                        </ul>

                        {{-- Every state always listed, at zero too: "0 down" is
                             news, and a legend that loses entries is harder to
                             read. Unknown is a dashed outline: nobody has heard
                             from it, which is not the same as switched off. --}}
                        <p class="mt-3 grid grid-cols-2 gap-x-3 gap-y-1 text-xs text-fg-muted">
                            <span class="inline-flex items-center gap-1.5"><span class="size-2 rounded-sm bg-emerald-500"></span><span x-text="count('ok')"></span> healthy</span>
                            <span class="inline-flex items-center gap-1.5"><span class="size-2 rounded-sm bg-amber-500"></span><span x-text="count('slow')"></span> slow or busy</span>
                            <span class="inline-flex items-center gap-1.5"><span class="size-2 rounded-sm bg-red-500"></span><span x-text="count('down')"></span> down</span>
                            <span class="inline-flex items-center gap-1.5"><span class="size-2 rounded-sm border border-dashed border-slate-400"></span><span x-text="count('unknown')"></span> unknown</span>
                            <span class="inline-flex items-center gap-1.5"><span class="size-2 rounded-sm bg-slate-400"></span><span x-text="count('maintenance')"></span> maintenance</span>
                        </p>
                    </div>

                    <div class="mt-4 border-t border-line">
                        <p class="px-5 pb-1 pt-3 text-xs font-medium text-fg-muted">Needs attention</p>
                        <template x-if="problems.length === 0">
                            <p class="flex items-center gap-2 px-5 pb-4 pt-1 text-sm text-fg-muted">
                                <x-icon name="check-circle" :fill="true" class="size-4 text-emerald-600" />
                                Every access point is healthy.
                            </p>
                        </template>
                        <ul class="pb-2" x-show="problems.length > 0">
                            <template x-for="ap in problems.slice(0, 6)" :key="ap.id">
                                <li>
                                    <a :href="'{{ url('engineer/aps') }}/' + ap.id"
                                       class="flex items-center gap-3 px-5 py-2 transition hover:bg-sunken/60">
                                        <span class="size-2 flex-none rounded-full" :class="tone(toneOf(ap)).dot"></span>
                                        <span class="min-w-0 flex-1">
                                            <span class="block font-mono text-sm text-fg" x-text="ap.code"></span>
                                            <span class="block truncate text-xs text-fg-subtle" x-text="reading(ap)"></span>
                                        </span>
                                        <span class="inline-flex h-6 flex-none items-center rounded-md px-2 text-xs font-medium ring-1 ring-inset"
                                              :class="tone(toneOf(ap)).badge" x-text="label(ap)"></span>
                                    </a>
                                </li>
                            </template>
                        </ul>
                    </div>

                    <x-slot:footer>
                        <a href="{{ route('engineer.aps.index') }}" class="link inline-flex items-center gap-1">
                            All access points <x-icon name="arrow-right" class="size-3.5" />
                        </a>
                    </x-slot:footer>
                </x-card>
            </div>
        </div>
    </div>

    @push('scripts')
        <script>
            function apSummary(initial, intervalSeconds, tones) {
                const rank = { down: 0, slow: 1, unknown: 2 };

                return {
                    data: initial,

                    // ok, slow, down, unknown or maintenance. Slow is degraded,
                    // or a latest reading over the limits before the status,
                    // which waits for several in a row, has turned Degraded.
                    // Unknown is a silent agent: its last numbers are not now.
                    health(ap) {
                        if (ap.status === 'offline') return 'down';
                        if (ap.status === 'unknown') return 'unknown';
                        if (ap.status === 'maintenance') return 'maintenance';
                        if (ap.status === 'degraded' || ap.over_threshold) return 'slow';
                        return 'ok';
                    },

                    // "Slow now" for latency or loss, "Busy now" for nearly full.
                    label(ap) {
                        if (ap.status !== 'online' || ! ap.over_threshold) return ap.status_label;
                        return ap.breach === 'busy' ? 'Busy now' : 'Slow now';
                    },

                    toneOf(ap) {
                        return ap.status === 'online' && ap.over_threshold ? 'attention' : ap.tone;
                    },

                    // The figure behind the label, so the line says why.
                    reading(ap) {
                        const room = ap.location_name ?? ap.location ?? 'No location';
                        // Its numbers are from before its agent went quiet.
                        if (ap.status === 'unknown') return ap.last_seen ? `${room}, last heard ${ap.last_seen}` : room;
                        if (ap.breach === 'busy') return `${room}, ${ap.clients} of ${ap.capacity} connected`;
                        if (ap.latency_ms === null) return room;
                        return `${room}, ${ap.latency_ms} ms, ${ap.packet_loss_pct}% loss`;
                    },

                    count(state) {
                        return this.data.access_points.filter((ap) => this.health(ap) === state).length;
                    },

                    get rooms() {
                        const byRoom = {};
                        for (const ap of this.data.access_points) {
                            const code = ap.location ?? '';
                            (byRoom[code] ??= { code, name: ap.location_name ?? 'No location', aps: [] }).aps.push(ap);
                        }
                        return Object.values(byRoom).sort((a, b) => a.code.localeCompare(b.code));
                    },

                    get problems() {
                        return this.data.access_points
                            .filter((ap) => this.health(ap) in rank)
                            .sort((a, b) => rank[this.health(a)] - rank[this.health(b)] || a.code.localeCompare(b.code));
                    },

                    tone(name) {
                        return tones[name] ?? tones.neutral;
                    },

                    start() {
                        window.pollWhileVisible(() => this.refresh(), intervalSeconds);
                    },

                    async refresh() {
                        try {
                            this.data = await window.fetchJson('{{ route('api.dashboard') }}');
                        } catch (e) {
                            // Keep the last good numbers.
                        }
                    },
                };
            }
        </script>
    @endpush
</x-app-layout>
