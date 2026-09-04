<x-app-layout>
    <x-slot name="title">AP status</x-slot>

    {{-- Read-only (FR-209). An assistant standing in a lab wants to know
         whether the Wi-Fi problem a student is reporting is already known —
         not to change anything. Same data as the engineer dashboard, no
         controls, no links into engineer screens they cannot reach. --}}

    <div x-data="apStatusBoard(@js($data), {{ $pollInterval }})" x-init="start()">
        <x-page-header title="Access point status"
                       subtitle="Live Wi-Fi status across the monitored zones.">
            <x-slot:actions>
                <span class="text-xs text-slate-500">
                    Updated <span x-text="updatedAgo">just now</span>
                    <template x-if="data.readings_age">
                        <span> · readings from <span x-text="data.readings_age"></span></span>
                    </template>
                </span>
            </x-slot:actions>
        </x-page-header>

        <template x-if="data.stale">
            <div class="mb-4">
                <x-alert type="warning">
                    These statuses are from <span x-text="data.readings_age"></span> and may be
                    out of date. Ask an engineer to check the collector.
                </x-alert>
            </div>
        </template>

        <div class="mb-4 grid gap-3 grid-cols-3">
            <x-stat-tile label="Online" tone="healthy">
                <x-slot:value><span x-text="data.counts.online">{{ $data['counts']['online'] }}</span></x-slot:value>
            </x-stat-tile>
            <x-stat-tile label="Degraded" tone="attention">
                <x-slot:value><span x-text="data.counts.degraded">{{ $data['counts']['degraded'] }}</span></x-slot:value>
            </x-stat-tile>
            <x-stat-tile label="Offline" tone="urgent">
                <x-slot:value><span x-text="data.counts.offline">{{ $data['counts']['offline'] }}</span></x-slot:value>
            </x-stat-tile>
        </div>

        <x-card title="Access points" flush>
            <template x-if="data.access_points.length === 0">
                <div class="px-6 py-12 text-center">
                    <h3 class="text-sm font-semibold text-slate-900">Nothing to show yet</h3>
                    <p class="mx-auto mt-1 max-w-sm text-sm text-slate-500">
                        Access point status appears here once the network monitoring is running.
                    </p>
                </div>
            </template>

            <ul class="divide-y divide-slate-100" x-show="data.access_points.length > 0">
                <template x-for="ap in data.access_points" :key="ap.id">
                    <li class="flex items-center justify-between gap-3 px-6 py-3">
                        <div class="min-w-0">
                            <p class="text-sm font-medium text-slate-900" x-text="ap.code"></p>
                            <p class="text-xs text-slate-500">
                                <span x-text="ap.location"></span>
                                <span x-text="' · ' + ap.clients + ' connected'"></span>
                            </p>
                        </div>
                        <span class="flex-none inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset"
                              :class="{
                                  'bg-emerald-50 text-emerald-700 ring-emerald-600/20': ap.tone === 'healthy',
                                  'bg-amber-50 text-amber-800 ring-amber-600/20': ap.tone === 'attention',
                                  'bg-red-50 text-red-700 ring-red-600/20': ap.tone === 'urgent',
                                  'bg-slate-50 text-slate-600 ring-slate-500/20': ap.tone === 'neutral',
                              }"
                              x-text="ap.status_label"></span>
                    </li>
                </template>
            </ul>
        </x-card>
    </div>

    @push('scripts')
        <script>
            function apStatusBoard(initial, intervalSeconds) {
                return {
                    data: initial,
                    updatedAgo: 'just now',

                    start() {
                        this.tick();
                        setInterval(() => this.refresh(), intervalSeconds * 1000);
                    },

                    async refresh() {
                        try {
                            const response = await fetch('{{ route('api.dashboard') }}', {
                                headers: { 'Accept': 'application/json' },
                            });
                            if (! response.ok) return;
                            this.data = await response.json();
                            this.tick();
                        } catch (e) {
                            // Keep the last good numbers rather than blanking
                            // the screen because one request timed out.
                        }
                    },

                    tick() {
                        const seconds = Math.max(0, Math.round((Date.now() - new Date(this.data.generated_at).getTime()) / 1000));
                        this.updatedAgo = seconds < 60 ? seconds + 's ago' : Math.round(seconds / 60) + 'm ago';
                    },
                };
            }
        </script>
    @endpush
</x-app-layout>
