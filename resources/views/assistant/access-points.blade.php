<x-app-layout>
    <x-slot name="title">Access points</x-slot>

    {{-- Read-only (FR-209). An assistant standing in a lab wants to know
         whether the Wi-Fi problem a student is reporting is already known,
         not to change anything. Same data as the engineer dashboard, no
         controls, no links into engineer screens they cannot reach.

         Problems sort to the top and there is a filter for them, because
         "is it just this room?" is the question this screen is opened for. --}}

    @php
        $tones = collect(\App\Support\Tone::all())->mapWithKeys(fn ($tone) => [$tone => [
            'badge' => \App\Support\Tone::badge($tone),
            'dot' => \App\Support\Tone::solid($tone),
        ]]);
    @endphp

    <div x-data="apStatusBoard(@js($data), {{ $pollInterval }}, @js($tones))" x-init="start()">
        <x-page-header title="Access point status"
                       subtitle="Live Wi-Fi status across the monitored zones.">
            <x-slot:actions>
                <span class="inline-flex items-center gap-2 rounded-full border border-line bg-surface px-3 py-1 text-xs text-fg-muted shadow-xs">
                    <span class="relative flex size-2">
                        <span class="absolute inline-flex size-full animate-ping rounded-full bg-emerald-400 opacity-60 motion-reduce:hidden"
                              x-show="! data.stale"></span>
                        <span class="relative inline-flex size-2 rounded-full" :class="data.stale ? 'bg-amber-500' : 'bg-emerald-500'"></span>
                    </span>
                    Updated <span x-text="updatedAgo">just now</span>
                </span>
            </x-slot:actions>
        </x-page-header>

        <template x-if="data.stale">
            <div class="mb-6">
                <x-alert type="warning">
                    These statuses are from <span x-text="data.readings_age"></span> and may be
                    out of date. Ask an engineer to check the collector.
                </x-alert>
            </div>
        </template>

        <div class="mb-6 grid grid-cols-3 gap-3">
            <x-stat-tile label="Online" tone="healthy" icon="wifi">
                <x-slot:value><span x-text="data.counts.online">{{ $data['counts']['online'] }}</span></x-slot:value>
            </x-stat-tile>
            <x-stat-tile label="Degraded" tone="attention" icon="warning">
                <x-slot:value><span x-text="data.counts.degraded">{{ $data['counts']['degraded'] }}</span></x-slot:value>
            </x-stat-tile>
            <x-stat-tile label="Offline" tone="urgent" icon="wifi-off">
                <x-slot:value><span x-text="data.counts.offline">{{ $data['counts']['offline'] }}</span></x-slot:value>
            </x-stat-tile>
        </div>

        <x-card title="Access points" flush>
            <x-slot:header>
                <div class="inline-flex rounded-lg bg-sunken p-0.5 text-xs font-medium" role="group" aria-label="Show">
                    <button type="button" @click="problemsOnly = false" :aria-pressed="! problemsOnly"
                            class="rounded-md px-2.5 py-1 transition"
                            :class="! problemsOnly ? 'bg-surface text-fg shadow-xs' : 'text-fg-muted hover:text-fg'">All</button>
                    <button type="button" @click="problemsOnly = true" :aria-pressed="problemsOnly"
                            class="rounded-md px-2.5 py-1 transition"
                            :class="problemsOnly ? 'bg-surface text-fg shadow-xs' : 'text-fg-muted hover:text-fg'">
                        Problems <span class="tabular-nums" x-text="'(' + problems + ')'"></span>
                    </button>
                </div>
            </x-slot:header>

            <div class="border-b border-line px-5 py-3">
                <label class="relative block">
                    <span class="sr-only">Find an access point or location</span>
                    <x-icon name="search" class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-fg-subtle" />
                    <input type="search" x-model.debounce.150ms="query" placeholder="Find by code or location"
                           class="h-9 w-full pl-9 text-sm">
                </label>
            </div>

            <template x-if="data.access_points.length === 0">
                <x-empty-state title="Nothing to show yet" icon="wifi"
                               message="Access point status appears here once the network monitoring is running." />
            </template>

            <template x-if="data.access_points.length > 0 && rows.length === 0">
                <x-empty-state title="Nothing matches" icon="funnel"
                               message="No access point matches that. Everything else is listed under All." />
            </template>

            <ul class="divide-y divide-line" x-show="rows.length > 0">
                <template x-for="ap in rows" :key="ap.id">
                    <li class="flex items-center gap-3 px-5 py-3">
                        <span class="size-2 flex-none rounded-full" :class="tone(ap.tone).dot"></span>
                        <div class="min-w-0 flex-1">
                            <p class="font-mono text-sm font-medium text-fg" x-text="ap.code"></p>
                            <p class="truncate text-xs text-fg-muted">
                                <span x-text="ap.location ?? 'No location'"></span>
                                <template x-if="ap.latency_ms !== null && ap.tone !== 'urgent'">
                                    <span x-text="' · ' + ap.latency_ms + ' ms'"></span>
                                </template>
                                <template x-if="ap.tone === 'urgent' && ap.last_seen">
                                    <span x-text="' · last seen ' + ap.last_seen"></span>
                                </template>
                            </p>
                        </div>

                        {{-- How busy it is, against what it can take. --}}
                        <div class="hidden w-28 flex-none sm:block" x-show="ap.capacity">
                            <div class="flex justify-between text-2xs text-fg-subtle">
                                <span>Clients</span>
                                <span class="tabular-nums" x-text="(ap.clients ?? '-') + ' / ' + ap.capacity"></span>
                            </div>
                            <div class="mt-1 h-1 overflow-hidden rounded-full bg-sunken">
                                <div class="h-full rounded-full bg-brand-500" :style="`width: ${Math.min(100, Math.round(ap.clients / ap.capacity * 100))}%`"></div>
                            </div>
                        </div>
                        <span class="text-xs tabular-nums text-fg-muted sm:hidden" x-text="(ap.clients ?? '-') + ' on'"></span>

                        <span class="inline-flex h-6 flex-none items-center rounded-md px-2 text-xs font-medium ring-1 ring-inset"
                              :class="tone(ap.tone).badge" x-text="ap.status_label"></span>
                    </li>
                </template>
            </ul>
        </x-card>
    </div>

    @push('scripts')
        <script>
            function apStatusBoard(initial, intervalSeconds, tones) {
                // Worst first, so the answer to "is it known?" is at the top.
                const rank = { urgent: 0, attention: 1, neutral: 2, healthy: 3 };

                return {
                    data: initial,
                    updatedAgo: 'just now',
                    problemsOnly: false,
                    query: '',

                    get problems() {
                        return this.data.access_points.filter((ap) => ap.tone === 'urgent' || ap.tone === 'attention').length;
                    },

                    get rows() {
                        const q = this.query.trim().toLowerCase();

                        return this.data.access_points
                            .filter((ap) => ! this.problemsOnly || ap.tone === 'urgent' || ap.tone === 'attention')
                            .filter((ap) => q === '' || `${ap.code} ${ap.location ?? ''}`.toLowerCase().includes(q))
                            .slice()
                            .sort((a, b) => (rank[a.tone] ?? 9) - (rank[b.tone] ?? 9) || a.code.localeCompare(b.code));
                    },

                    tone(name) {
                        return tones[name] ?? tones.neutral;
                    },

                    start() {
                        this.tick();
                        window.pollWhileVisible(() => this.refresh(), intervalSeconds);
                    },

                    async refresh() {
                        try {
                            this.data = await window.fetchJson('{{ route('api.dashboard') }}');
                            this.tick();
                        } catch (e) {
                            // Keep the last good numbers rather than blanking
                            // the screen because one request timed out.
                        }
                    },

                    tick() {
                        this.updatedAgo = window.ago(this.data.generated_at);
                    },
                };
            }
        </script>
    @endpush
</x-app-layout>
