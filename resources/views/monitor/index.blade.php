<x-app-layout>
    <x-slot name="title">Network monitor</x-slot>

    {{--
        The Network monitor (FR-312 to FR-314). One page for all three roles.

        The first paint and every poll come from NetworkMonitorService::snapshot(),
        so the page and the endpoint cannot disagree. Filtering is client-side:
        the whole estate is a few dozen rows, already on the page, and a filter
        that round-tripped to the server would fight the poll. The status and
        location are mirrored into the query string so a view can be shared.

        Status badge classes arrive in the data from App\Support\Tone, which
        Tailwind scans. This view never maps a tone to a colour itself, except
        the dots on the summary tiles below, which are written out in full.

        @see App\Http\Controllers\NetworkMonitorController
    --}}

    <div x-data="networkMonitor(@js($data), {
            endpoint: @js(route('api.monitor')),
            detailEndpoint: @js(route('api.monitor.aps.show', ['accessPoint' => '__ID__'])),
            interval: {{ $pollInterval }},
         })"
         x-init="start()">

        <x-page-header title="Network monitor"
                       :subtitle="'Campus connectivity from '.['simulated' => 'synthetic', 'live' => 'live', 'mixed' => 'synthetic and live'][$feed].' telemetry, every access point in one place.'">
            <x-slot:actions>
                <x-button variant="secondary" @click="refresh()" x-bind:disabled="refreshing">
                    <x-icon name="refresh" class="size-4" x-bind:class="refreshing && 'animate-spin'" />
                    Refresh
                </x-button>

                @if ($canSimulate)
                    <x-button icon="flask" @click="$dispatch('open-modal', 'run-simulation')">Run simulation</x-button>
                @endif
            </x-slot:actions>
        </x-page-header>

        {{-- Where the numbers come from, and how fresh they are. --}}
        <div class="mb-6 flex flex-wrap items-center gap-x-6 gap-y-2 rounded-xl border border-line bg-surface px-4 py-3 text-xs text-fg-muted shadow-xs">
            <span class="inline-flex items-center gap-2 font-medium text-fg">
                <span class="relative flex size-2">
                    <span class="absolute inline-flex size-full animate-ping rounded-full bg-emerald-400 opacity-60 motion-reduce:hidden"
                          x-show="! failing"></span>
                    <span class="relative inline-flex size-2 rounded-full" :class="failing ? 'bg-red-500' : 'bg-emerald-500'"></span>
                </span>
                {{ ['simulated' => 'Simulated feed', 'live' => 'Live feed', 'mixed' => 'Simulated and live feed'][$feed] }}
            </span>
            <span>Last sample <span class="font-medium text-fg" x-text="data.last_sample ?? 'none yet'"></span></span>
            <span class="max-md:hidden">
                Raw readings kept {{ config('atlas.retention.raw_days') }} days, hourly summaries {{ config('atlas.retention.aggregate_months') }} months
            </span>
            <span x-show="failing" x-cloak class="font-medium text-red-700">Refresh failed. Showing the last good data.</span>

            @if ($canSimulate)
                <form method="POST" action="{{ route('monitor.sample') }}" class="ml-auto"
                      x-data="{ busy: false }" @submit="busy = true">
                    @csrf
                    <button type="submit" :disabled="busy" class="btn btn-secondary btn-sm">
                        <x-icon name="lightning" class="size-3.5" />
                        <span x-text="busy ? 'Sampling' : 'Generate sample'">Generate sample</span>
                    </button>
                </form>
            @endif
        </div>

        {{-- Every reading is older than the offline window: nothing is being
             collected. Unless every one is unknown because its agent isn't
             reporting, which the scheduler can't fix. --}}
        <div x-show="data.counts.total > 0 && data.counts.stale === data.counts.total && data.counts.silent < data.counts.total" x-cloak>
            <x-alert type="warning" class="mb-6" title="Nothing is being collected">
                No reading has arrived in the last {{ config('atlas.thresholds.offline_after_min') }} minutes, so every
                status below is unknown. Start the collector with
                <code class="kbd">php artisan schedule:work</code>{{ $canSimulate ? ', or take a sample now.' : '.' }}
            </x-alert>
        </div>
        <div x-show="data.counts.total > 0 && data.counts.silent === data.counts.total" x-cloak>
            <x-alert type="warning" class="mb-6" title="No agent is reporting">
                The agents watching these access points are not reporting, so every status below is unknown.
                Check that each agent is running and can reach Atlas.
            </x-alert>
        </div>

        {{-- The estate by status. Each tile is also the filter for the list. --}}
        <div class="mb-6 grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-6" role="group" aria-label="Filter by status">
            <template x-for="tab in tabs" :key="tab.key">
                <button type="button" @click="status = tab.key" :aria-pressed="(status === tab.key).toString()"
                        class="rounded-xl border bg-surface p-4 text-left shadow-xs transition"
                        :class="status === tab.key ? 'border-brand-600 ring-1 ring-brand-600' : 'border-line hover:border-line-strong hover:shadow-sm'">
                    <span class="flex items-center gap-2 text-xs text-fg-muted">
                        <span class="size-2 rounded-full" :class="{
                            'bg-brand-500': tab.key === 'all',
                            'bg-emerald-500': tab.key === 'online',
                            'bg-amber-500': tab.key === 'degraded',
                            'bg-red-500': tab.key === 'offline',
                            'bg-slate-400': tab.key === 'maintenance',
                            {{-- Hollow: no reading is not a colour. Fuchsia is
                                 reserved for an unrecognised tone, i.e. a bug. --}}
                            'border border-slate-400': tab.key === 'stale',
                        }"></span>
                        <span x-text="tab.label"></span>
                    </span>
                    <span class="mt-2 block text-2xl font-semibold leading-none tracking-tight tabular-nums text-fg" x-text="tab.count"></span>
                </button>
            </template>
        </div>

        {{-- Activity --}}
        <x-card class="mb-6">
            <x-slot:title>Network activity</x-slot:title>
            <x-slot:header>
                @include('monitor.partials.range')
            </x-slot:header>

            <div class="mb-3 flex flex-wrap items-center gap-x-5 gap-y-1 text-xs text-fg-muted">
                <span class="inline-flex items-center gap-1.5"><span class="size-2 rounded-sm bg-brand-500" aria-hidden="true"></span>Connected clients</span>
                <span class="inline-flex items-center gap-1.5"><span class="size-2 rounded-sm bg-emerald-500" aria-hidden="true"></span>Throughput (Mbps)</span>
                <span class="ml-auto text-fg-subtle">All access points</span>
            </div>

            <div class="relative h-64 sm:h-72">
                <canvas x-ref="activity" role="img"
                        aria-label="Connected clients and throughput across all access points over the selected range. The table below lists each access point's current figures."></canvas>

                <div x-show="! pointsFor(data.activity).length" x-cloak
                     class="absolute inset-0 grid place-items-center rounded-lg border border-dashed border-line text-center">
                    <div class="max-w-sm px-6">
                        <p class="text-sm font-medium text-fg">No readings in this range</p>
                        <p class="mt-1 text-xs text-fg-muted">
                            The chart fills as the collector runs, once a minute with
                            <code class="font-mono">php artisan schedule:work</code>.
                        </p>
                    </div>
                </div>
            </div>
        </x-card>

        {{-- Access points --}}
        <x-card flush>
            <x-slot:title>Access points</x-slot:title>
            <x-slot:header>
                <span class="text-xs tabular-nums text-fg-muted">
                    <span x-text="filtered.length"></span> of <span x-text="data.counts.total"></span>
                </span>
            </x-slot:header>

            <div class="flex flex-wrap gap-2 border-b border-line px-5 py-3">
                <label class="relative min-w-[12rem] flex-1 sm:max-w-xs">
                    <span class="sr-only">Search access points</span>
                    <x-icon name="search" class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-fg-subtle" />
                    <input type="search" x-model="search" placeholder="Search access points" class="h-9 w-full pl-9 text-sm">
                </label>

                <label>
                    <span class="sr-only">Location</span>
                    <select x-model="location" class="h-9 w-auto py-0 pl-3 pr-8 text-sm">
                        <option value="">All locations</option>
                        @foreach ($locations as $id => $label)
                            <option value="{{ $id }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
            </div>

            {{-- Desktop: a table. --}}
            <div class="relative overflow-x-auto max-md:hidden" x-show="filtered.length">
                <table class="min-w-full text-sm">
                    <thead class="border-b border-line bg-sunken/60 text-left text-xs font-medium text-fg-muted">
                        <tr>
                            <th scope="col" class="h-10 px-5 font-medium">Access point</th>
                            <th scope="col" class="h-10 px-4 font-medium">Status</th>
                            <th scope="col" class="h-10 px-4 text-right font-medium">Clients</th>
                            <th scope="col" class="h-10 px-4 font-medium">Utilisation</th>
                            <th scope="col" class="h-10 px-4 text-right font-medium">Latency</th>
                            <th scope="col" class="h-10 px-4 font-medium">Last seen</th>
                            <th scope="col" class="h-10 w-10 px-4"><span class="sr-only">Details</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        <template x-for="ap in filtered" :key="ap.id">
                            <tr class="group cursor-pointer transition-colors hover:bg-sunken/70" @click="inspect(ap)">
                                <td class="px-5 py-3">
                                    <div class="flex items-center gap-3">
                                        <span class="grid size-8 flex-none place-items-center rounded-lg bg-sunken" :class="ap.accent" aria-hidden="true">
                                            <x-icon name="wifi" class="size-4" />
                                        </span>
                                        <div class="min-w-0">
                                            <p class="font-mono text-sm font-medium text-fg" x-text="ap.code"></p>
                                            <p class="mt-0.5 truncate text-xs text-fg-subtle" x-text="ap.location_name ?? 'No location'"></p>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="flex flex-wrap items-center gap-1.5">
                                        <span class="inline-flex h-6 items-center whitespace-nowrap rounded-md px-2 text-xs font-medium ring-1 ring-inset"
                                              :class="ap.badge" x-text="ap.state_label"></span>
                                        <template x-if="ap.scenario">
                                            <span class="inline-flex h-6 items-center gap-1 whitespace-nowrap rounded-md px-1.5 text-2xs font-medium ring-1 ring-inset"
                                                  :class="ap.scenario_badge" :title="'Simulated scenario: ' + ap.scenario_label">
                                                <x-icon name="flask" class="size-3" />
                                                <span x-text="ap.scenario_label"></span>
                                            </span>
                                        </template>
                                    </div>
                                </td>
                                <td class="px-4 py-3 text-right tabular-nums">
                                    <span class="text-fg" x-text="ap.clients ?? '-'"></span><span class="text-fg-subtle" x-text="' / ' + (ap.capacity ?? '-')"></span>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="flex items-center gap-2.5">
                                        <span class="block h-1.5 w-16 overflow-hidden rounded-full bg-sunken" aria-hidden="true">
                                            <span class="block h-full rounded-full" :class="ap.fill" :style="`width: ${ap.utilization ?? 0}%`"></span>
                                        </span>
                                        <span class="text-xs tabular-nums text-fg-muted" x-text="ap.utilization === null ? '-' : ap.utilization + '%'"></span>
                                    </div>
                                </td>
                                <td class="px-4 py-3 text-right tabular-nums text-fg" x-text="ap.latency_ms === null ? '-' : ap.latency_ms + ' ms'"></td>
                                <td class="whitespace-nowrap px-4 py-3 text-fg-muted" x-text="ap.last_seen ?? 'Never'"></td>
                                <td class="px-4 py-3 text-right">
                                    <button type="button" @click.stop="inspect(ap)" :aria-label="'Details for ' + ap.code"
                                            class="btn-icon size-8 text-fg-subtle group-hover:text-fg">
                                        <x-icon name="arrow-up-right" class="size-4" />
                                    </button>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>

            {{-- Phone: one row per access point, from the same list. --}}
            <ul class="divide-y divide-line md:hidden" x-show="filtered.length">
                <template x-for="ap in filtered" :key="ap.id">
                    <li>
                        <button type="button" @click="inspect(ap)" class="w-full px-5 py-3.5 text-left active:bg-sunken">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="font-mono text-sm font-medium text-fg" x-text="ap.code"></p>
                                    <p class="mt-0.5 truncate text-xs text-fg-muted" x-text="ap.location_name ?? 'No location'"></p>
                                </div>
                                <span class="inline-flex h-6 flex-none items-center rounded-md px-2 text-xs font-medium ring-1 ring-inset"
                                      :class="ap.badge" x-text="ap.state_label"></span>
                            </div>
                            <p class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs tabular-nums text-fg-muted">
                                <span x-text="(ap.clients ?? '-') + ' clients'"></span>
                                <span x-text="ap.utilization === null ? '- util' : ap.utilization + '% util'"></span>
                                <span x-text="ap.latency_ms === null ? '- ms' : ap.latency_ms + ' ms'"></span>
                                <span x-show="ap.scenario" x-text="'Simulated: ' + ap.scenario_label"></span>
                            </p>
                        </button>
                    </li>
                </template>
            </ul>

            <div x-show="! filtered.length" x-cloak>
                <x-empty-state icon="wifi" title="No access points match this view"
                               message="Try another status, clear the search, or pick all locations." />
            </div>
        </x-card>

        {{-- One access point --}}
        <x-modal name="ap-detail" title="Access point" maxWidth="2xl">
            <template x-if="detail">
                <div>
                    <div class="flex flex-wrap items-center gap-3">
                        <h3 class="font-mono text-2xl font-semibold text-fg" x-text="detail.code"></h3>
                        <span class="inline-flex h-6 items-center rounded-md px-2 text-xs font-medium ring-1 ring-inset"
                              :class="detail.badge" x-text="detail.state_label"></span>
                        <span x-show="detail.scenario" class="text-xs text-fg-muted" x-text="'Simulated: ' + detail.scenario_label"></span>
                    </div>
                    <p class="mt-1 text-sm text-fg-muted">
                        <span x-text="detail.location_name ?? 'No location'"></span>
                        <span x-show="detail.ip_address"> · <span class="font-mono" x-text="detail.ip_address"></span></span>
                        <span x-show="detail.model"> · <span x-text="detail.model"></span></span>
                    </p>

                    <dl class="my-5 grid grid-cols-3 divide-x divide-line rounded-xl border border-line bg-sunken/40 py-4 text-center">
                        <div>
                            <dt class="text-xs text-fg-muted">Clients</dt>
                            <dd class="mt-1 text-2xl font-semibold tabular-nums text-fg" x-text="detail.clients ?? '-'"></dd>
                        </div>
                        <div>
                            <dt class="text-xs text-fg-muted">Utilisation</dt>
                            <dd class="mt-1 text-2xl font-semibold tabular-nums text-fg" x-text="detail.utilization === null ? '-' : detail.utilization + '%'"></dd>
                        </div>
                        <div>
                            <dt class="text-xs text-fg-muted">Latency</dt>
                            <dd class="mt-1 text-2xl font-semibold tabular-nums text-fg" x-text="detail.latency_ms === null ? '-' : detail.latency_ms + ' ms'"></dd>
                        </div>
                    </dl>

                    <div x-show="detail.state === 'stale'">
                        <x-alert type="warning" class="mb-4">
                            <span x-text="detail.silent ? 'Its agent is not reporting.' : 'No recent reading.'"></span>
                            This access point's status is unknown, which is not the same as offline.
                        </x-alert>
                    </div>

                    <div class="mb-3 flex items-center justify-between gap-4">
                        <p class="text-sm font-medium text-fg">Activity</p>
                        @include('monitor.partials.range')
                    </div>
                    <div class="relative h-48">
                        {{-- An id, not x-ref: this sits inside <x-modal>'s own
                             x-data, so a ref would register on the modal and the
                             page component could not see it. --}}
                        <canvas id="ap-detail-chart" role="img" :aria-label="'Clients and throughput for ' + detail.code"></canvas>
                        <p x-show="detailLoading" class="absolute inset-0 grid place-items-center text-xs text-fg-muted">Loading</p>
                        <p x-show="! detailLoading && ! pointsFor(detail.activity ?? []).length"
                           class="absolute inset-0 grid place-items-center text-xs text-fg-muted">No readings in this range.</p>
                    </div>

                    <div class="mt-4 flex flex-wrap items-center justify-between gap-3 text-xs text-fg-muted">
                        <span>Last seen <span class="font-medium text-fg" x-text="detail.last_seen ?? 'never'"></span></span>
                        @if (auth()->user()->role !== \App\Enums\UserRole::Assistant)
                            <a :href="@js(route('engineer.aps.show', ['accessPoint' => '__ID__'])).replace('__ID__', detail.id)"
                               class="link inline-flex items-center gap-1 font-medium">
                                Full 24-hour history <x-icon name="arrow-right" class="size-3.5" />
                            </a>
                        @endif
                    </div>
                </div>
            </template>
            <p x-show="detailFailed" x-cloak class="text-sm text-red-700">Could not load this access point. Try again.</p>
        </x-modal>

        @if ($canSimulate)
            {{-- The simulation control room --}}
            <x-modal name="run-simulation" title="Simulation control room" maxWidth="lg">
                <form method="POST" action="{{ route('monitor.simulate') }}" id="run-simulation-form" class="space-y-5"
                      x-data="{ busy: false }" @submit="busy = true">
                    @csrf

                    <p class="text-sm text-fg-muted">
                        Changes what the simulator reports. Nothing on a real network is touched, and the
                        system reacts through its ordinary rules: classification, tickets and alarms.
                    </p>

                    <x-form.select name="access_point_id" label="Target access point" blank="All simulated access points"
                                   :options="collect($data['access_points'])->whereIn('id', $targets)->mapWithKeys(fn ($ap) => [$ap['id'] => $ap['code'].($ap['location'] ? ' · '.$ap['location'] : '')])->all()" />

                    <fieldset>
                        <legend class="mb-2 text-sm font-medium text-fg">Scenario</legend>
                        <div class="space-y-2">
                            @foreach ($scenarios as $scenario)
                                <label class="flex cursor-pointer items-center gap-3 rounded-lg border border-line p-3.5 transition hover:bg-sunken
                                              has-[:checked]:border-brand-600 has-[:checked]:bg-brand-50/60 has-[:checked]:ring-1 has-[:checked]:ring-brand-600
                                              has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-brand-500/40">
                                    <input type="radio" name="scenario" value="{{ $scenario->value }}" class="sr-only"
                                           @checked(old('scenario', 'congestion') === $scenario->value)>
                                    <span class="size-2 flex-none rounded-full {{ \App\Support\Tone::solid($scenario->tone()) }}" aria-hidden="true"></span>
                                    <span class="min-w-0 flex-1">
                                        <span class="block text-sm font-medium text-fg">{{ $scenario->label() }}</span>
                                        <span class="mt-0.5 block text-xs text-fg-muted">{{ $scenario->description() }}</span>
                                    </span>
                                </label>
                            @endforeach

                            <label class="flex cursor-pointer items-center gap-3 rounded-lg border border-line p-3.5 transition hover:bg-sunken
                                          has-[:checked]:border-brand-600 has-[:checked]:bg-brand-50/60 has-[:checked]:ring-1 has-[:checked]:ring-brand-600
                                          has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-brand-500/40">
                                <input type="radio" name="scenario" value="{{ \App\Http\Requests\Telemetry\RunSimulationRequest::RECOVERY }}" class="sr-only"
                                       @checked(old('scenario') === \App\Http\Requests\Telemetry\RunSimulationRequest::RECOVERY)>
                                <span class="size-2 flex-none rounded-full {{ \App\Support\Tone::solid(\App\Support\Tone::HEALTHY) }}" aria-hidden="true"></span>
                                <span class="min-w-0 flex-1">
                                    <span class="block text-sm font-medium text-fg">Recovery</span>
                                    <span class="mt-0.5 block text-xs text-fg-muted">Clear the scenario and return to normal telemetry</span>
                                </span>
                            </label>
                        </div>
                        @error('scenario')
                            <p class="mt-2 flex items-start gap-1.5 text-xs font-medium text-red-700">
                                <x-icon name="warning" :fill="true" class="mt-px size-3.5 flex-none" /> <span>{{ $message }}</span>
                            </p>
                        @enderror
                    </fieldset>
                </form>

                <x-slot:footer>
                    <x-button variant="secondary" @click="$dispatch('close-modal', 'run-simulation')">Cancel</x-button>
                    <x-button type="submit" form="run-simulation-form" icon="flask">Run simulation</x-button>
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
                 * A canvas cannot read a Tailwind class, so the two series read
                 * the same theme variables the legend swatches above the chart
                 * are drawn from. Read on every draw, so a poll after the theme
                 * changes picks up the other palette.
                 */
                const colour = (name, alpha) => window.themeToken(name, alpha);

                const draw = (canvas, points, existing) => {
                    // A line needs two points. With one, the first ten minutes
                    // after the collector starts, it is drawn as a dot rather
                    // than an empty chart.
                    const pointRadius = points.length === 1 ? 4 : 0;

                    const data = {
                        labels: points.map((p) => p.label),
                        datasets: [
                            {
                                label: 'Connected clients',
                                data: points.map((p) => p.clients),
                                borderColor: colour('c-brand-500'),
                                backgroundColor: colour('c-brand-500', 0.12),
                                borderWidth: 2,
                            },
                            {
                                label: 'Throughput (Mbps)',
                                data: points.map((p) => p.throughput),
                                borderColor: colour('c-emerald-500'),
                                backgroundColor: colour('c-emerald-500', 0.06),
                                borderWidth: 1.6,
                            },
                        ].map((set) => ({ ...set, fill: true, tension: 0.35, pointRadius, pointBackgroundColor: set.borderColor, pointHoverRadius: Math.max(3, pointRadius) })),
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
                            { key: 'all', label: 'All', count: c.total },
                            { key: 'online', label: 'Online', count: c.online },
                            { key: 'degraded', label: 'Degraded', count: c.degraded },
                            { key: 'offline', label: 'Offline', count: c.offline },
                            { key: 'maintenance', label: 'Maintenance', count: c.maintenance },
                            { key: 'stale', label: 'Unknown', count: c.stale },
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
