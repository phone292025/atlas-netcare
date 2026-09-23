<x-app-layout>
    <x-slot name="title">Network monitor</x-slot>

    {{--
        The Network monitor (FR-312 to FR-314). One page for all three roles.

        The first paint and every poll come from NetworkMonitorService::snapshot(),
        so the page and the endpoint cannot disagree. Filtering is client-side:
        the whole estate is a few dozen rows, already on the page, and a filter
        that round-tripped to the server would fight the poll. The status tab
        and location are mirrored into the query string so a view can be shared.

        Status badge classes arrive in the data from App\Support\Tone, which
        Tailwind scans. This view never maps a tone to a colour itself.

        @see App\Http\Controllers\NetworkMonitorController
    --}}

    <div x-data="networkMonitor(@js($data), {
            endpoint: @js(route('api.monitor')),
            detailEndpoint: @js(route('api.monitor.aps.show', ['accessPoint' => '__ID__'])),
            interval: {{ $pollInterval }},
         })"
         x-init="start()">

        <x-page-header title="Network monitor"
                       :subtitle="'Campus connectivity · '.($simulated ? 'synthetic telemetry' : 'live telemetry')">
            <x-slot:actions>
                <button type="button" @click="refresh()" :disabled="refreshing"
                        class="inline-flex items-center gap-2 rounded-md bg-white px-3 py-2 text-sm font-medium text-slate-700 ring-1 ring-slate-300 hover:bg-slate-50
                               focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 disabled:opacity-60">
                    <x-icon name="refresh" class="h-4 w-4" x-bind:class="refreshing && 'animate-spin'" />
                    Refresh
                </button>

                @if ($canSimulate)
                    <button type="button" @click="$dispatch('open-modal', 'run-simulation')"
                            class="inline-flex items-center gap-2 rounded-md bg-brand-600 px-3 py-2 text-sm font-medium text-white hover:bg-brand-700
                                   focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500">
                        <x-icon name="flask" class="h-4 w-4" />
                        Run simulation
                    </button>
                @endif
            </x-slot:actions>
        </x-page-header>

        {{-- Feed status --}}
        <div class="mb-6 flex flex-wrap items-center gap-x-6 gap-y-2 border-y border-slate-200 py-3 text-xs text-slate-500">
            <span class="inline-flex items-center gap-2 text-[11px] font-medium uppercase tracking-wide text-emerald-700">
                <span class="h-1.5 w-1.5 rounded-full bg-emerald-500" aria-hidden="true"></span>
                {{ $simulated ? 'Simulated feed' : 'Live feed' }}
            </span>
            <span>Last sample: <span class="text-slate-700" x-text="data.last_sample ?? 'none yet'"></span></span>
            <span class="max-md:hidden">
                Raw readings kept {{ config('atlas.retention.raw_days') }} days · hourly summaries {{ config('atlas.retention.aggregate_months') }} months
            </span>
            <span x-show="failing" x-cloak class="text-red-700">Refresh failed — showing the last good data</span>

            @if ($canSimulate)
                <form method="POST" action="{{ route('monitor.sample') }}" class="ml-auto"
                      x-data="{ busy: false }" @submit="busy = true">
                    @csrf
                    <button type="submit" :disabled="busy"
                            class="rounded-md bg-white px-3 py-1.5 text-xs font-medium text-slate-700 ring-1 ring-slate-300 hover:bg-slate-50
                                   focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 disabled:opacity-60">
                        <span x-text="busy ? 'Sampling…' : 'Generate sample'">Generate sample</span>
                    </button>
                </form>
            @endif
        </div>

        {{-- Every reading is older than the offline window: nothing is being collected. --}}
        <div x-show="data.counts.total > 0 && data.counts.stale === data.counts.total" x-cloak>
            <x-alert type="warning" class="mb-6">
                No reading has arrived in the last {{ config('atlas.thresholds.offline_after_min') }} minutes, so every
                status below is unknown. Start the collector with
                <code class="font-mono text-xs">php artisan schedule:work</code>{{ $canSimulate ? ', or take a sample now.' : '.' }}
            </x-alert>
        </div>

        {{-- Status filter --}}
        <div class="mb-7 flex gap-6 overflow-x-auto border-b border-slate-200" role="tablist" aria-label="Filter by status">
            <template x-for="tab in tabs" :key="tab.key">
                <button type="button" role="tab" @click="status = tab.key" :aria-selected="(status === tab.key).toString()"
                        class="relative flex flex-none items-center gap-2 whitespace-nowrap pb-3 pt-1 text-sm transition-colors
                               focus:outline-none focus-visible:text-brand-700"
                        :class="status === tab.key
                            ? 'text-brand-700 after:absolute after:inset-x-0 after:bottom-0 after:h-0.5 after:rounded-full after:bg-brand-500'
                            : 'text-slate-500 hover:text-slate-900'">
                    <span x-text="tab.label"></span>
                    <span class="rounded bg-white px-1.5 py-px font-mono text-[10px] text-slate-500 ring-1 ring-inset ring-slate-200"
                          x-text="tab.count"></span>
                </button>
            </template>
        </div>

        {{-- Activity --}}
        <section class="mb-8" aria-labelledby="activity-heading">
            <div class="mb-4 flex items-center justify-between gap-4">
                <h2 id="activity-heading" class="text-[15px] font-medium text-slate-900">Network activity</h2>
                @include('monitor.partials.range')
            </div>

            <div class="mb-3 flex flex-wrap items-center gap-x-5 gap-y-1 text-[11px] text-slate-500">
                <span class="inline-flex items-center gap-1.5"><span class="h-2 w-2 rounded-sm bg-brand-500" aria-hidden="true"></span>Connected clients</span>
                <span class="inline-flex items-center gap-1.5"><span class="h-2 w-2 rounded-sm bg-emerald-500" aria-hidden="true"></span>Throughput (Mbps)</span>
                <span class="ml-auto text-[10px] uppercase tracking-widest text-slate-400">
                    {{ $simulated ? 'Synthetic telemetry' : 'Live telemetry' }} · all access points
                </span>
            </div>

            <div class="relative h-64 sm:h-72">
                <canvas x-ref="activity" role="img"
                        aria-label="Connected clients and throughput across all access points over the selected range. The table below lists each access point's current figures."></canvas>

                <div x-show="! pointsFor(data.activity).length" x-cloak
                     class="absolute inset-0 grid place-items-center rounded-lg border border-dashed border-slate-200 text-center">
                    <div class="max-w-sm px-6">
                        <p class="text-sm font-medium text-slate-900">No readings in this range</p>
                        <p class="mt-1 text-xs text-slate-500">
                            The chart fills as the collector runs, once a minute with
                            <code class="font-mono">php artisan schedule:work</code>.
                        </p>
                    </div>
                </div>
            </div>
        </section>

        {{-- Access points --}}
        <section class="border-t border-slate-200 pt-7" aria-labelledby="aps-heading">
            <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                <h2 id="aps-heading" class="text-[15px] font-medium text-slate-900">Access points</h2>

                <div class="flex gap-2 max-sm:w-full">
                    <label class="relative max-sm:flex-1">
                        <span class="sr-only">Search access points</span>
                        <x-icon name="search" class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                        <input type="search" x-model="search" placeholder="Search access points…"
                               class="block w-full rounded-md border-slate-300 py-2 pl-9 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500 sm:w-56">
                    </label>

                    <label>
                        <span class="sr-only">Location</span>
                        <select x-model="location"
                                class="block rounded-md border-slate-300 py-2 text-sm shadow-sm focus:border-brand-500 focus:ring-brand-500">
                            <option value="">All locations</option>
                            @foreach ($locations as $id => $label)
                                <option value="{{ $id }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>
                </div>
            </div>

            {{-- Desktop: a table. --}}
            <div class="overflow-x-auto rounded-lg bg-white ring-1 ring-slate-200 max-md:hidden">
                <table class="min-w-full text-sm">
                    <thead class="bg-slate-50 text-left text-[11px] uppercase tracking-wider text-slate-500">
                        <tr>
                            <th scope="col" class="px-4 py-3 font-medium">Access point</th>
                            <th scope="col" class="px-4 py-3 font-medium">Status</th>
                            <th scope="col" class="px-4 py-3 text-right font-medium">Clients</th>
                            <th scope="col" class="px-4 py-3 font-medium">Utilization</th>
                            <th scope="col" class="px-4 py-3 text-right font-medium">Latency</th>
                            <th scope="col" class="px-4 py-3 font-medium">Last seen</th>
                            <th scope="col" class="px-4 py-3"><span class="sr-only">Details</span></th>
                        </tr>
                    </thead>
                    <tbody class="text-slate-700">
                        <template x-for="ap in filtered" :key="ap.id">
                            <tr class="cursor-pointer border-t border-slate-200 transition-colors hover:bg-slate-50" @click="inspect(ap)">
                                <td class="px-4 py-3">
                                    <div class="flex items-center gap-3">
                                        <span class="grid h-8 w-8 flex-none place-items-center rounded-md bg-slate-50 ring-1 ring-inset ring-slate-200"
                                              :class="ap.accent" aria-hidden="true">
                                            <x-icon name="wifi" class="h-4 w-4" />
                                        </span>
                                        <div class="min-w-0">
                                            <p class="font-mono text-xs text-slate-900" x-text="ap.code"></p>
                                            <p class="mt-0.5 truncate text-[11px] text-slate-500" x-text="ap.location_name ?? 'No location'"></p>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="flex flex-wrap items-center gap-1.5">
                                        <span class="inline-flex items-center rounded-md px-2 py-0.5 text-xs font-medium ring-1 ring-inset whitespace-nowrap"
                                              :class="ap.badge" x-text="ap.state_label"></span>
                                        <template x-if="ap.scenario">
                                            <span class="inline-flex items-center gap-1 rounded-md px-1.5 py-0.5 text-[10px] font-medium uppercase tracking-wide ring-1 ring-inset whitespace-nowrap"
                                                  :class="ap.scenario_badge" :title="'Simulated scenario: ' + ap.scenario_label">
                                                <x-icon name="flask" class="h-3 w-3" />
                                                <span x-text="ap.scenario_label"></span>
                                            </span>
                                        </template>
                                    </div>
                                </td>
                                <td class="px-4 py-3 text-right font-mono text-xs tabular-nums">
                                    <span class="text-slate-900" x-text="ap.clients ?? '—'"></span><span class="text-slate-400" x-text="' / ' + (ap.capacity ?? '—')"></span>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="flex items-center gap-2.5">
                                        <span class="block h-1 w-16 overflow-hidden rounded-full bg-slate-200" aria-hidden="true">
                                            <span class="block h-full rounded-full" :class="ap.fill" :style="`width: ${ap.utilization ?? 0}%`"></span>
                                        </span>
                                        <span class="font-mono text-xs tabular-nums" x-text="ap.utilization === null ? '—' : ap.utilization + '%'"></span>
                                    </div>
                                </td>
                                <td class="px-4 py-3 text-right font-mono text-xs tabular-nums" x-text="ap.latency_ms === null ? '—' : ap.latency_ms + ' ms'"></td>
                                <td class="px-4 py-3 font-mono text-xs tabular-nums text-slate-500" x-text="ap.last_seen ?? 'never'"></td>
                                <td class="px-4 py-3 text-right">
                                    <button type="button" @click.stop="inspect(ap)" :aria-label="'Details for ' + ap.code"
                                            class="rounded p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500">
                                        <x-icon name="arrow-up-right" class="h-4 w-4" />
                                    </button>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>

            {{-- Phone: one card per access point, from the same list. --}}
            <ul class="space-y-2 md:hidden">
                <template x-for="ap in filtered" :key="ap.id">
                    <li>
                        <button type="button" @click="inspect(ap)"
                                class="w-full rounded-lg bg-white p-4 text-left ring-1 ring-slate-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="font-mono text-sm text-slate-900" x-text="ap.code"></p>
                                    <p class="mt-0.5 truncate text-xs text-slate-500" x-text="ap.location_name ?? 'No location'"></p>
                                </div>
                                <span class="inline-flex flex-none items-center rounded-md px-2 py-0.5 text-xs font-medium ring-1 ring-inset"
                                      :class="ap.badge" x-text="ap.state_label"></span>
                            </div>
                            <p class="mt-3 flex flex-wrap gap-x-4 gap-y-1 font-mono text-xs text-slate-500 tabular-nums">
                                <span x-text="(ap.clients ?? '—') + ' clients'"></span>
                                <span x-text="ap.utilization === null ? '— util' : ap.utilization + '% util'"></span>
                                <span x-text="ap.latency_ms === null ? '— ms' : ap.latency_ms + ' ms'"></span>
                                <span x-show="ap.scenario" x-text="'sim: ' + ap.scenario_label"></span>
                            </p>
                        </button>
                    </li>
                </template>
            </ul>

            <div x-show="! filtered.length" x-cloak class="rounded-lg border border-dashed border-slate-200">
                <x-empty-state icon="wifi" title="No access points match this view"
                               message="Try another status, clear the search, or pick all locations." />
            </div>

            <p class="mt-3 flex items-center justify-between gap-4 text-[11px] text-slate-400">
                <span>Showing <span x-text="filtered.length"></span> of <span x-text="data.counts.total"></span> access points</span>
                <span class="uppercase tracking-widest">{{ $simulated ? 'Synthetic feed' : 'Live feed' }}</span>
            </p>
        </section>

        {{-- One access point --}}
        <x-modal name="ap-detail" title="Access point" maxWidth="2xl">
            <template x-if="detail">
                <div>
                    <div class="flex flex-wrap items-center gap-3">
                        <h3 class="text-2xl font-semibold text-slate-900" x-text="detail.code"></h3>
                        <span class="inline-flex items-center rounded-md px-2 py-0.5 text-xs font-medium ring-1 ring-inset"
                              :class="detail.badge" x-text="detail.state_label"></span>
                        <span x-show="detail.scenario" class="text-xs text-slate-500" x-text="'Simulated: ' + detail.scenario_label"></span>
                    </div>
                    <p class="mt-1 text-sm text-slate-500">
                        <span x-text="detail.location_name ?? 'No location'"></span>
                        <span x-show="detail.ip_address"> · <span class="font-mono" x-text="detail.ip_address"></span></span>
                        <span x-show="detail.model"> · <span x-text="detail.model"></span></span>
                    </p>

                    <dl class="my-5 grid grid-cols-3 divide-x divide-slate-200 border-y border-slate-200 py-4 text-center">
                        <div>
                            <dt class="text-xs text-slate-500">Clients</dt>
                            <dd class="mt-1 text-2xl font-semibold text-slate-900 tabular-nums" x-text="detail.clients ?? '—'"></dd>
                        </div>
                        <div>
                            <dt class="text-xs text-slate-500">Utilization</dt>
                            <dd class="mt-1 text-2xl font-semibold text-slate-900 tabular-nums" x-text="detail.utilization === null ? '—' : detail.utilization + '%'"></dd>
                        </div>
                        <div>
                            <dt class="text-xs text-slate-500">Latency</dt>
                            <dd class="mt-1 text-2xl font-semibold text-slate-900 tabular-nums" x-text="detail.latency_ms === null ? '—' : detail.latency_ms + ' ms'"></dd>
                        </div>
                    </dl>

                    <div x-show="detail.state === 'stale'">
                        <x-alert type="warning" class="mb-4">
                            No recent reading. This access point's status is unknown, which is not the same as offline.
                        </x-alert>
                    </div>

                    <div class="mb-3 flex items-center justify-between gap-4">
                        <p class="text-sm font-medium text-slate-900">Activity</p>
                        @include('monitor.partials.range')
                    </div>
                    <div class="relative h-48">
                        {{-- An id, not x-ref: this sits inside <x-modal>'s own
                             x-data, so a ref would register on the modal and the
                             page component could not see it. --}}
                        <canvas id="ap-detail-chart" role="img" :aria-label="'Clients and throughput for ' + detail.code"></canvas>
                        <p x-show="detailLoading" class="absolute inset-0 grid place-items-center text-xs text-slate-500">Loading…</p>
                        <p x-show="! detailLoading && ! pointsFor(detail.activity ?? []).length"
                           class="absolute inset-0 grid place-items-center text-xs text-slate-500">No readings in this range.</p>
                    </div>

                    <div class="mt-4 flex flex-wrap items-center justify-between gap-3 text-xs text-slate-500">
                        <span>Last seen: <span class="font-mono" x-text="detail.last_seen ?? 'never'"></span></span>
                        @if (auth()->user()->role !== \App\Enums\UserRole::Assistant)
                            <a :href="@js(route('engineer.aps.show', ['accessPoint' => '__ID__'])).replace('__ID__', detail.id)"
                               class="font-medium text-brand-700 hover:underline">Full 24-hour history →</a>
                        @endif
                    </div>
                </div>
            </template>
            <p x-show="detailFailed" x-cloak class="text-sm text-red-700">Could not load this access point. Try again.</p>
        </x-modal>

        @if ($canSimulate)
            {{-- The simulation control room --}}
            <x-modal name="run-simulation" title="Simulation control room" maxWidth="lg">
                <form method="POST" action="{{ route('monitor.simulate') }}" id="run-simulation-form" class="space-y-4"
                      x-data="{ busy: false }" @submit="busy = true">
                    @csrf

                    <p class="text-xs text-slate-500">
                        Changes what the simulated driver reports. Nothing on a real network is touched, and the
                        system reacts through its ordinary rules: classification, tickets and alarms.
                    </p>

                    <x-form.select name="access_point_id" label="Target access point" blank="All access points"
                                   :options="collect($data['access_points'])->mapWithKeys(fn ($ap) => [$ap['id'] => $ap['code'].($ap['location'] ? ' · '.$ap['location'] : '')])->all()" />

                    <fieldset>
                        <legend class="mb-2 text-sm font-medium text-slate-700">Scenario</legend>
                        <div class="space-y-2">
                            @foreach ($scenarios as $scenario)
                                <label class="flex cursor-pointer items-center gap-3 rounded-lg bg-slate-50/50 p-3.5 ring-1 ring-inset ring-slate-200
                                              has-[:checked]:bg-brand-500/10 has-[:checked]:ring-brand-500 has-[:focus-visible]:ring-2">
                                    <input type="radio" name="scenario" value="{{ $scenario->value }}" class="sr-only"
                                           @checked(old('scenario', 'congestion') === $scenario->value)>
                                    <span class="h-2 w-2 flex-none rounded-full {{ \App\Support\Tone::solid($scenario->tone()) }}" aria-hidden="true"></span>
                                    <span class="min-w-0 flex-1">
                                        <span class="block text-sm font-medium text-slate-900">{{ $scenario->label() }}</span>
                                        <span class="mt-0.5 block text-xs text-slate-500">{{ $scenario->description() }}</span>
                                    </span>
                                </label>
                            @endforeach

                            <label class="flex cursor-pointer items-center gap-3 rounded-lg bg-slate-50/50 p-3.5 ring-1 ring-inset ring-slate-200
                                          has-[:checked]:bg-brand-500/10 has-[:checked]:ring-brand-500 has-[:focus-visible]:ring-2">
                                <input type="radio" name="scenario" value="{{ \App\Http\Requests\Telemetry\RunSimulationRequest::RECOVERY }}" class="sr-only"
                                       @checked(old('scenario') === \App\Http\Requests\Telemetry\RunSimulationRequest::RECOVERY)>
                                <span class="h-2 w-2 flex-none rounded-full {{ \App\Support\Tone::solid(\App\Support\Tone::HEALTHY) }}" aria-hidden="true"></span>
                                <span class="min-w-0 flex-1">
                                    <span class="block text-sm font-medium text-slate-900">Recovery</span>
                                    <span class="mt-0.5 block text-xs text-slate-500">Clear the scenario and return to normal telemetry</span>
                                </span>
                            </label>
                        </div>
                        @error('scenario')
                            <p class="mt-2 text-xs text-red-600">{{ $message }}</p>
                        @enderror
                    </fieldset>
                </form>

                <x-slot:footer>
                    <button type="button" @click="$dispatch('close-modal', 'run-simulation')"
                            class="rounded-md bg-white px-3 py-2 text-sm font-medium text-slate-700 ring-1 ring-slate-300 hover:bg-slate-50">
                        Cancel
                    </button>
                    <button type="submit" form="run-simulation-form"
                            class="inline-flex items-center gap-2 rounded-md bg-brand-600 px-3 py-2 text-sm font-medium text-white hover:bg-brand-700">
                        <x-icon name="flask" class="h-4 w-4" />
                        Run simulation
                    </button>
                </x-slot:footer>
            </x-modal>
        @endif
    </div>

    @push('scripts')
        <script>
            /*
             * The Network monitor's client state. Plain <script>, pushed so it
             * runs before Alpine starts — see the note in layouts/app.blade.php.
             *
             * Chart instances live in this closure, NOT in the returned object.
             * Alpine wraps its state in a Proxy, and a Chart.js instance inside
             * one recurses on every update.
             */
            function networkMonitor(initial, options) {
                let activityChart = null;
                let detailChart = null;
                const params = new URLSearchParams(window.location.search);

                /*
                 * A canvas cannot read a Tailwind class, so the two series name
                 * their own colours: the same pair as the legend swatches above
                 * the chart. Hex with an alpha suffix, which is the convention
                 * App\Support\Tone::chart() and the chart component already
                 * use — and the same reason they keep the values beside the
                 * classes they match. If the Tailwind 500 scale changes, these
                 * change with it.
                 */
                const CLIENTS = '#6366f1';    // brand-500
                const THROUGHPUT = '#10b981'; // emerald-500

                const draw = (canvas, points, existing) => {
                    const data = {
                        labels: points.map((p) => p.label),
                        datasets: [
                            {
                                label: 'Connected clients',
                                data: points.map((p) => p.clients),
                                borderColor: CLIENTS,
                                backgroundColor: CLIENTS + '20', // ~12% alpha
                                borderWidth: 2,
                            },
                            {
                                label: 'Throughput (Mbps)',
                                data: points.map((p) => p.throughput),
                                borderColor: THROUGHPUT,
                                backgroundColor: THROUGHPUT + '0f', // ~6% alpha
                                borderWidth: 1.6,
                            },
                        ].map((set) => ({ ...set, fill: true, tension: 0.35, pointRadius: 0, pointHoverRadius: 3 })),
                    };

                    if (existing) {
                        existing.data = data;
                        existing.update();
                        return existing;
                    }

                    return new window.Chart(canvas, {
                        type: 'line',
                        data,
                        options: {
                            interaction: { mode: 'index', intersect: false },
                            plugins: { legend: { display: false } },
                            scales: {
                                x: { grid: { display: false }, ticks: { autoSkip: true, maxTicksLimit: 12, maxRotation: 0 } },
                                y: { beginAtZero: true, ticks: { maxTicksLimit: 5 } },
                            },
                        },
                    });
                };

                return {
                    data: initial,
                    status: params.get('status') ?? 'all',
                    location: params.get('location') ?? '',
                    search: '',
                    range: '24h',
                    refreshing: false,
                    failing: false,
                    detail: null,
                    detailLoading: false,
                    detailFailed: false,

                    get tabs() {
                        const c = this.data.counts;
                        return [
                            { key: 'all', label: 'All access points', count: c.total },
                            { key: 'online', label: 'Online', count: c.online },
                            { key: 'degraded', label: 'Degraded', count: c.degraded },
                            { key: 'offline', label: 'Offline', count: c.offline },
                            { key: 'maintenance', label: 'Maintenance', count: c.maintenance },
                            { key: 'stale', label: 'Stale / unknown', count: c.stale },
                        ];
                    },

                    get filtered() {
                        const q = this.search.trim().toLowerCase();
                        return this.data.access_points.filter((ap) =>
                            (this.status === 'all' || ap.state === this.status)
                            && (this.location === '' || String(ap.location_id) === String(this.location))
                            && (q === '' || `${ap.code} ${ap.location ?? ''} ${ap.location_name ?? ''}`.toLowerCase().includes(q)));
                    },

                    pointsFor(series) {
                        const hours = { '1h': 1, '6h': 6, '24h': 24 }[this.range];
                        const since = Date.now() - hours * 3600 * 1000;
                        return series.filter((p) => new Date(p.at).getTime() >= since);
                    },

                    start() {
                        this.drawActivity();
                        this.$watch('range', () => {
                            this.drawActivity();
                            this.drawDetail();
                        });
                        this.$watch('status', () => this.syncUrl());
                        this.$watch('location', () => this.syncUrl());

                        window.pollWhileVisible(() => this.refresh(), options.interval);
                    },

                    async refresh() {
                        this.refreshing = true;
                        try {
                            this.data = await window.fetchJson(options.endpoint);
                            this.failing = false;
                            this.drawActivity();
                        } catch (e) {
                            // Keep the last good numbers and say so, as the dashboard does.
                            this.failing = true;
                        } finally {
                            this.refreshing = false;
                        }
                    },

                    drawActivity() {
                        activityChart = draw(this.$refs.activity, this.pointsFor(this.data.activity), activityChart);
                    },

                    drawDetail() {
                        const canvas = document.getElementById('ap-detail-chart');
                        if (! this.detail?.activity || ! canvas) return;
                        if (detailChart && detailChart.canvas !== canvas) {
                            detailChart.destroy();
                            detailChart = null;
                        }
                        detailChart = draw(canvas, this.pointsFor(this.detail.activity), detailChart);
                    },

                    async inspect(ap) {
                        this.detail = { ...ap };
                        this.detailLoading = true;
                        this.detailFailed = false;
                        this.$dispatch('open-modal', 'ap-detail');

                        try {
                            this.detail = await window.fetchJson(options.detailEndpoint.replace('__ID__', ap.id));
                            await this.$nextTick();
                            this.drawDetail();
                        } catch (e) {
                            this.detailFailed = true;
                        } finally {
                            this.detailLoading = false;
                        }
                    },

                    syncUrl() {
                        const url = new URL(window.location.href);
                        this.status === 'all' ? url.searchParams.delete('status') : url.searchParams.set('status', this.status);
                        this.location === '' ? url.searchParams.delete('location') : url.searchParams.set('location', this.location);
                        window.history.replaceState(null, '', url);
                    },
                };
            }
        </script>
    @endpush
</x-app-layout>
