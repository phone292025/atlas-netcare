<x-app-layout>
    <x-slot name="title">Access points</x-slot>

    {{--
        Every monitored access point, live (FR-301, FR-302).

        POLLING, NOT WEBSOCKETS: ADR-0005. Telemetry is collected once a
        minute, so a socket would deliver nothing sooner, and a socket server
        is infrastructure seven students would have to run and explain.

        The first paint and every poll after it come from the same
        TelemetryService::dashboardData(), so they cannot disagree.
    --}}

    @php
        $tones = collect(\App\Support\Tone::all())->mapWithKeys(fn ($tone) => [$tone => [
            'badge' => \App\Support\Tone::badge($tone),
            'dot' => \App\Support\Tone::solid($tone),
        ]]);
    @endphp

    <div x-data="apDashboard(@js($data), {{ $pollInterval }}, @js($tones))" x-init="start()">
        <x-page-header title="Access point health"
                       subtitle="Live status across the monitored zones. Worst first.">
            <x-slot:actions>
                {{-- Two different times, and both matter. "Updated" is when
                     this page last fetched; "readings" is when the collector
                     last measured anything. Only the second one says whether
                     the statuses below are current. --}}
                <span class="inline-flex items-center gap-2 rounded-full border border-line bg-surface px-3 py-1 text-xs text-fg-muted shadow-xs">
                    <span class="relative flex size-2">
                        <span class="absolute inline-flex size-full animate-ping rounded-full bg-emerald-400 opacity-60 motion-reduce:hidden"
                              x-show="! data.stale && ! failing"></span>
                        <span class="relative inline-flex size-2 rounded-full"
                              :class="failing ? 'bg-red-500' : (data.stale ? 'bg-amber-500' : 'bg-emerald-500')"></span>
                    </span>
                    <span>
                        Updated <span x-text="updatedAgo">just now</span><span x-show="data.readings_age">, readings from <span x-text="data.readings_age"></span></span><span x-show="! data.readings_age">, no current readings</span>
                    </span>
                    <span x-show="failing" x-cloak class="font-medium text-red-700">Refresh failed</span>
                </span>
            </x-slot:actions>
        </x-page-header>

        <template x-if="data.stale">
            <div class="mb-6">
                <x-alert type="warning" title="The collector has stopped">
                    The newest reading is from <span x-text="data.readings_age"></span>, so the statuses
                    below are the last known state, not a measurement. Start it with
                    <code class="kbd">php artisan schedule:work</code>.
                </x-alert>
            </div>
        </template>

        <div class="mb-6 grid grid-cols-2 gap-3 lg:grid-cols-4">
            {{-- The number is passed as a SLOT, not the `value` prop, so Alpine
                 can keep it current. --}}
            <x-stat-tile label="Online" tone="healthy" icon="wifi" :unit="'of '.$data['counts']['total']">
                <x-slot:value><span x-text="data.counts.online">{{ $data['counts']['online'] }}</span></x-slot:value>
            </x-stat-tile>
            <x-stat-tile label="Degraded" tone="attention" icon="warning">
                <x-slot:value><span x-text="data.counts.degraded">{{ $data['counts']['degraded'] }}</span></x-slot:value>
            </x-stat-tile>
            <x-stat-tile label="Offline" tone="urgent" icon="wifi-off">
                <x-slot:value><span x-text="data.counts.offline">{{ $data['counts']['offline'] }}</span></x-slot:value>
            </x-stat-tile>
            <x-stat-tile label="Clients connected" tone="neutral" icon="users">
                <x-slot:value><span x-text="data.clients_now">{{ $data['clients_now'] }}</span></x-slot:value>
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
                <label class="relative block max-w-sm">
                    <span class="sr-only">Find an access point or location</span>
                    <x-icon name="search" class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-fg-subtle" />
                    <input type="search" x-model.debounce.150ms="query" placeholder="Find by code or location"
                           class="h-9 w-full pl-9 text-sm">
                </label>
            </div>

            <template x-if="data.access_points.length === 0">
                <x-empty-state title="No access points yet" icon="wifi"
                               message="An administrator registers access points under the asset inventory. Once the collector runs, their status appears here." />
            </template>

            <template x-if="data.access_points.length > 0 && rows.length === 0">
                <x-empty-state title="Nothing matches" icon="funnel"
                               message="No access point matches that. Everything else is listed under All." />
            </template>

            <div class="overflow-x-auto" x-show="rows.length > 0">
                <table class="min-w-full text-sm">
                    <thead class="border-b border-line bg-sunken/60 text-left text-xs font-medium text-fg-muted">
                        <tr>
                            <th scope="col" class="h-10 px-5 font-medium">Access point</th>
                            <th scope="col" class="h-10 px-4 font-medium">Status</th>
                            <th scope="col" class="h-10 px-4 font-medium">Clients</th>
                            <th scope="col" class="h-10 px-4 text-right font-medium">Latency</th>
                            <th scope="col" class="h-10 px-4 text-right font-medium">Loss</th>
                            <th scope="col" class="h-10 px-4 font-medium">Last seen</th>
                            <th scope="col" class="h-10 w-10 px-4"><span class="sr-only">Open</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        <template x-for="ap in rows" :key="ap.id">
                            <tr class="group cursor-pointer transition-colors hover:bg-sunken/70" @click="window.location = url(ap)">
                                <td class="whitespace-nowrap px-5 py-3">
                                    <span class="flex items-center gap-3">
                                        <span class="size-2 flex-none rounded-full" :class="tone(toneOf(ap)).dot"></span>
                                        <span>
                                            <a :href="url(ap)" class="block font-mono text-sm font-medium text-fg group-hover:text-brand-700" x-text="ap.code"></a>
                                            <span class="block text-xs text-fg-subtle" x-text="ap.location ?? 'No location'"></span>
                                        </span>
                                    </span>
                                </td>
                                <td class="px-4 py-3">
                                    <span class="inline-flex h-6 items-center rounded-md px-2 text-xs font-medium ring-1 ring-inset"
                                          :class="tone(toneOf(ap)).badge" x-text="label(ap)"></span>
                                </td>
                                <td class="px-4 py-3">
                                    <span class="flex w-32 items-center gap-2">
                                        <span class="h-1.5 flex-1 overflow-hidden rounded-full bg-sunken">
                                            <span class="block h-full rounded-full"
                                                  :class="load(ap) > 90 ? 'bg-amber-500' : 'bg-brand-500'"
                                                  :style="`width: ${load(ap)}%`"></span>
                                        </span>
                                        <span class="w-12 text-right text-xs tabular-nums text-fg-muted" x-text="(ap.clients ?? '-') + '/' + ap.capacity"></span>
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-right tabular-nums"
                                    :class="ap.latency_ms > {{ config('atlas.thresholds.latency_ms') }} ? 'font-medium text-red-700' : 'text-fg'">
                                    <span x-text="ap.latency_ms === null ? '-' : ap.latency_ms + ' ms'"></span>
                                </td>
                                <td class="px-4 py-3 text-right tabular-nums"
                                    :class="ap.packet_loss_pct > {{ config('atlas.thresholds.packet_loss_pct') }} ? 'font-medium text-red-700' : 'text-fg'">
                                    <span x-text="ap.packet_loss_pct === null ? '-' : ap.packet_loss_pct + '%'"></span>
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-fg-muted" x-text="ap.last_seen ?? 'Never'"></td>
                                <td class="px-4 py-3 text-right">
                                    <x-icon name="chevron-right" class="size-4 text-fg-subtle transition group-hover:translate-x-0.5 group-hover:text-fg" />
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </x-card>
    </div>

    @push('scripts')
        <script>
            function apDashboard(initial, intervalSeconds, tones) {
                // Worst first: the reason to open this screen is at the top.
                const rank = { urgent: 0, attention: 1, neutral: 2, healthy: 3 };

                return {
                    data: initial,
                    updatedAgo: 'just now',
                    failing: false,
                    problemsOnly: false,
                    query: '',

                    /*
                     * The status turns Degraded only after several readings in
                     * a row over the limits. Until then an Online access point
                     * whose latest reading is over them reads "Slow now" or
                     * "Busy now", so its red figure never sits next to a green
                     * badge, and it counts as a problem.
                     */
                    toneOf(ap) {
                        return ap.status === 'online' && ap.over_threshold ? 'attention' : ap.tone;
                    },

                    // "Slow now" for latency or loss, "Busy now" for nearly full.
                    label(ap) {
                        if (ap.status !== 'online' || ! ap.over_threshold) return ap.status_label;
                        return ap.breach === 'busy' ? 'Busy now' : 'Slow now';
                    },

                    // Unknown too: nobody can say it is fine.
                    isProblem(ap) {
                        return ap.status === 'unknown' || ['urgent', 'attention'].includes(this.toneOf(ap));
                    },

                    get problems() {
                        return this.data.access_points.filter((ap) => this.isProblem(ap)).length;
                    },

                    get rows() {
                        const q = this.query.trim().toLowerCase();

                        return this.data.access_points
                            .filter((ap) => ! this.problemsOnly || this.isProblem(ap))
                            .filter((ap) => q === '' || `${ap.code} ${ap.location ?? ''}`.toLowerCase().includes(q))
                            .slice()
                            .sort((a, b) => (rank[this.toneOf(a)] ?? 9) - (rank[this.toneOf(b)] ?? 9) || a.code.localeCompare(b.code));
                    },

                    tone(name) {
                        return tones[name] ?? tones.neutral;
                    },

                    load(ap) {
                        return ap.capacity ? Math.min(100, Math.round(ap.clients / ap.capacity * 100)) : 0;
                    },

                    url(ap) {
                        return '{{ url('engineer/aps') }}/' + ap.id;
                    },

                    start() {
                        this.tick();
                        window.pollWhileVisible(() => this.refresh(), intervalSeconds);
                    },

                    async refresh() {
                        try {
                            this.data = await window.fetchJson('{{ route('api.dashboard') }}');
                            this.failing = false;
                            this.tick();
                        } catch (e) {
                            // Keep the last good numbers on screen and say the
                            // refresh failed. Stale data that admits it beats
                            // a blank dashboard.
                            this.failing = true;
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
