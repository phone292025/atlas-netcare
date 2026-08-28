<x-app-layout>
    <x-slot name="title">AP health</x-slot>

    {{--
        The project's headline screen (FR-301, FR-302).

        POLLING, NOT WEBSOCKETS — ADR-0005. Telemetry is collected once a
        minute, so a socket would deliver nothing sooner, and a socket server
        is infrastructure seven students would have to run and explain.

        The first paint and every poll after it come from the same
        TelemetryService::dashboardData(), so they cannot disagree.
    --}}

    <div x-data="apDashboard(@js($data), {{ $pollInterval }})" x-init="start()">
        <x-page-header title="Access point health"
                       subtitle="Live status across the monitored zones.">
            <x-slot:actions>
                {{-- Say when the numbers are from. A dashboard that might be
                     stale and does not admit it is worse than no dashboard. --}}
                <span class="text-xs text-slate-500">
                    Updated <span x-text="updatedAgo">just now</span>
                    <template x-if="failing">
                        <span class="ml-1 font-medium text-red-600">— refresh failed</span>
                    </template>
                </span>
            </x-slot:actions>
        </x-page-header>

        <div class="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            {{-- The number is passed as a SLOT, not the `value` prop, so Alpine
                 can keep it current. ComponentSlot is Htmlable, so the
                 component's own {{ $value }} renders the markup rather than
                 escaping it — and the tile keeps its tabular-nums, which is
                 what stops the layout jittering every twenty seconds. --}}
            <x-stat-tile label="Online" tone="healthy" :unit="'of '.$data['counts']['total']">
                <x-slot:value><span x-text="data.counts.online">{{ $data['counts']['online'] }}</span></x-slot:value>
            </x-stat-tile>
            <x-stat-tile label="Degraded" tone="attention">
                <x-slot:value><span x-text="data.counts.degraded">{{ $data['counts']['degraded'] }}</span></x-slot:value>
            </x-stat-tile>
            <x-stat-tile label="Offline" tone="urgent">
                <x-slot:value><span x-text="data.counts.offline">{{ $data['counts']['offline'] }}</span></x-slot:value>
            </x-stat-tile>
            <x-stat-tile label="Clients connected" tone="neutral">
                <x-slot:value><span x-text="data.clients_now">{{ $data['clients_now'] }}</span></x-slot:value>
            </x-stat-tile>
        </div>

        <x-card title="Access points" subtitle="Every monitored access point and its latest reading." flush>
            <template x-if="data.access_points.length === 0">
                <div class="px-6 py-12 text-center">
                    <h3 class="text-sm font-semibold text-slate-900">No access points yet</h3>
                    <p class="mx-auto mt-1 max-w-sm text-sm text-slate-500">
                        An administrator registers access points under the asset inventory.
                        Once the collector runs, their status appears here.
                    </p>
                </div>
            </template>

            <div class="overflow-x-auto" x-show="data.access_points.length > 0">
                <table class="min-w-full text-sm">
                    <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th scope="col" class="px-4 py-3 text-left font-medium">Access point</th>
                            <th scope="col" class="px-4 py-3 text-left font-medium">Status</th>
                            <th scope="col" class="px-4 py-3 text-right font-medium">Clients</th>
                            <th scope="col" class="px-4 py-3 text-right font-medium">Latency</th>
                            <th scope="col" class="px-4 py-3 text-right font-medium">Loss</th>
                            <th scope="col" class="px-4 py-3 text-left font-medium">Last seen</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 text-slate-700">
                        <template x-for="ap in data.access_points" :key="ap.id">
                            <tr class="hover:bg-slate-50">
                                <td class="whitespace-nowrap px-4 py-3">
                                    <a :href="'{{ url('engineer/aps') }}/' + ap.id"
                                       class="font-medium text-slate-900 hover:text-brand-700" x-text="ap.code"></a>
                                    <span class="block text-xs text-slate-400" x-text="ap.location"></span>
                                </td>
                                <td class="px-4 py-3">
                                    {{-- The four tone classes are written out in
                                         full below so Tailwind's scanner sees
                                         them. Building "bg-${tone}-50" here
                                         would purge every one of them. --}}
                                    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset"
                                          :class="{
                                              'bg-emerald-50 text-emerald-700 ring-emerald-600/20': ap.tone === 'healthy',
                                              'bg-amber-50 text-amber-800 ring-amber-600/20': ap.tone === 'attention',
                                              'bg-red-50 text-red-700 ring-red-600/20': ap.tone === 'urgent',
                                              'bg-slate-50 text-slate-600 ring-slate-500/20': ap.tone === 'neutral',
                                          }"
                                          x-text="ap.status_label"></span>
                                </td>
                                <td class="px-4 py-3 text-right tabular-nums">
                                    <span x-text="ap.clients"></span>
                                    <span class="text-slate-400" x-text="'/ ' + ap.capacity"></span>
                                </td>
                                <td class="px-4 py-3 text-right tabular-nums"
                                    :class="ap.latency_ms > {{ config('atlas.thresholds.latency_ms') }} ? 'font-medium text-red-700' : ''">
                                    <span x-text="ap.latency_ms === null ? '—' : ap.latency_ms + ' ms'"></span>
                                </td>
                                <td class="px-4 py-3 text-right tabular-nums"
                                    :class="ap.packet_loss_pct > {{ config('atlas.thresholds.packet_loss_pct') }} ? 'font-medium text-red-700' : ''">
                                    <span x-text="ap.packet_loss_pct === null ? '—' : ap.packet_loss_pct + '%'"></span>
                                </td>
                                <td class="px-4 py-3 text-slate-500" x-text="ap.last_seen ?? 'Never'"></td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </x-card>
    </div>

    @push('scripts')
        <script>
            function apDashboard(initial, intervalSeconds) {
                return {
                    data: initial,
                    updatedAgo: 'just now',
                    failing: false,
                    timer: null,

                    start() {
                        this.tick();
                        this.timer = setInterval(() => this.refresh(), intervalSeconds * 1000);

                        // Stop polling while the tab is hidden. Nobody is
                        // reading it, and a laptop left open overnight would
                        // otherwise make four thousand pointless requests.
                        document.addEventListener('visibilitychange', () => {
                            if (document.hidden) {
                                clearInterval(this.timer);
                                this.timer = null;
                            } else if (! this.timer) {
                                this.refresh();
                                this.timer = setInterval(() => this.refresh(), intervalSeconds * 1000);
                            }
                        });
                    },

                    async refresh() {
                        try {
                            const response = await fetch('{{ route('api.dashboard') }}', {
                                headers: { 'Accept': 'application/json' },
                            });

                            if (! response.ok) throw new Error(response.status);

                            this.data = await response.json();
                            this.failing = false;
                            this.tick();
                        } catch (e) {
                            // Keep the last good numbers on screen and say the
                            // refresh failed. Blanking the dashboard because
                            // one request timed out is worse than slightly
                            // stale data that admits it.
                            this.failing = true;
                        }
                    },

                    tick() {
                        const at = new Date(this.data.generated_at);
                        const seconds = Math.max(0, Math.round((Date.now() - at.getTime()) / 1000));
                        this.updatedAgo = seconds < 60
                            ? seconds + 's ago'
                            : Math.round(seconds / 60) + 'm ago';
                    },
                };
            }
        </script>
    @endpush
</x-app-layout>
