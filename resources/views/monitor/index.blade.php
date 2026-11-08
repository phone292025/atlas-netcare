<x-app-layout>
    <x-slot name="title">Network monitor</x-slot>

    {{--
        The Network monitor (FR-312 to FR-314). One page for all three roles.

        It shows, in order: how the campus's day is going, one chart per
        unit; what needs attention now and who has it; how the whole estate
        is doing; and where each device is, by place, with what it hangs
        from. A device opens in a panel
        beside the page, which says where on its path the fault is.

        The first paint and every poll come from
        App\Services\Monitor\NetworkMonitorService::snapshot(), so the page
        and the endpoint cannot disagree. Filtering is client-side: the estate
        is already on the page, and a filter that round-tripped to the server
        would fight the poll. The status, the view and the open device are
        mirrored into the query string, so a view can be shared.

        Status classes arrive in the data from App\Support\Tone, which
        Tailwind scans. The status dots below are written out in full for the
        same reason.

        @see App\Http\Controllers\NetworkMonitorController
    --}}

    <div x-data="networkMonitor(@js($data), {
            endpoint: @js(route('api.monitor')),
            deviceEndpoint: @js(route('api.monitor.devices.show', ['device' => '__ID__'])),
            links: @js($links),
            interval: {{ $pollInterval }},
         })"
         x-init="start()"
         @keydown.escape.window="closeDevice()">

        <x-page-header title="Network monitor">
            <x-slot:actions>
                <x-button variant="secondary" @click="refresh()" x-bind:disabled="refreshing">
                    <x-icon name="refresh" class="size-4" x-bind:class="refreshing && 'animate-spin'" />
                    Refresh
                </x-button>

                @if ($canSimulate)
                    <x-button variant="secondary" icon="flask" @click="$dispatch('open-modal', 'run-simulation')">Simulation</x-button>
                @endif
            </x-slot:actions>
        </x-page-header>

        {{-- Where the numbers come from, and how fresh they are. --}}
        <p class="-mt-4 mb-6 flex flex-wrap items-center gap-x-5 gap-y-1 text-sm text-fg-muted lg:-mt-6">
            <span class="inline-flex items-center gap-2 font-medium text-fg">
                <span class="relative flex size-2">
                    <span class="absolute inline-flex size-full animate-ping rounded-full bg-emerald-400 opacity-60 motion-reduce:hidden"
                          x-show="! failing"></span>
                    <span class="relative inline-flex size-2 rounded-full" :class="failing ? 'bg-red-500' : 'bg-emerald-500'"></span>
                </span>
                {{ ['simulated' => 'Simulated feed', 'live' => 'Live feed', 'mixed' => 'Simulated and live feed'][$feed] }}
            </span>
            <span>Last reading <span class="font-medium tabular-nums text-fg" x-text="data.last_sample ?? 'none yet'"></span></span>
            <span x-text="agentSummary"></span>
            <span x-show="failing" x-cloak class="font-medium text-red-700">Refresh failed. Showing the last good data.</span>
        </p>

        {{-- Every reading is older than the offline window: nothing is being
             collected. Unless every one is unknown because its agent isn't
             reporting, which the scheduler can't fix. --}}
        <div x-show="data.counts.total > 0 && data.counts.stale === data.counts.total && data.counts.silent < data.counts.total" x-cloak>
            <x-alert type="warning" class="mb-6" title="Nothing is being collected">
                No reading has arrived in the last {{ config('atlas.thresholds.offline_after_min') }} minutes, so every
                status below is unknown. Start the collector with
                <code class="kbd">php artisan schedule:work</code>{{ $canSimulate ? ', or take a sample from Simulation.' : '.' }}
            </x-alert>
        </div>
        <div x-show="data.counts.total > 0 && data.counts.silent === data.counts.total" x-cloak>
            <x-alert type="warning" class="mb-6" title="No agent is reporting">
                The agents watching these devices are not reporting, so every status below is unknown.
                Check that each agent is running and can reach Atlas.
            </x-alert>
        </div>

        {{-- The day, one chart per unit, first: how the campus is doing
             at a glance, before what needs somebody. --}}
        <x-card flush class="mb-6">
            <x-slot:title>Campus activity</x-slot:title>
            <x-slot:header>
                @include('monitor.partials.range')
            </x-slot:header>

            <div class="grid divide-line lg:grid-cols-3 lg:divide-x max-lg:divide-y">
                @foreach ([
                    ['clients', 'Connected clients', '', 'c-brand-500', 'Connected clients across every access point over the selected range.'],
                    ['throughput', 'Throughput', 'Mbps', 'c-emerald-500', 'Throughput across every access point over the selected range, in Mbps.'],
                    ['latency', 'Latency, average', 'ms', 'c-amber-500', 'Average latency across every device over the selected range, in milliseconds.'],
                ] as [$series, $label, $unit, $colour, $description])
                    <div class="px-5 py-4">
                        <p class="text-xs text-fg-muted">{{ $label }}</p>
                        <p class="mt-0.5 text-xl font-semibold tabular-nums text-fg">
                            <span x-text="latest('{{ $series }}') ?? '-'"></span>
                            @if ($unit) <span class="text-sm font-medium text-fg-subtle">{{ $unit }}</span> @endif
                        </p>
                        <div class="relative mt-2 h-20 lg:h-28">
                            <canvas x-ref="{{ $series }}" data-colour="{{ $colour }}" role="img" aria-label="{{ $description }}"></canvas>
                            <p x-show="! pointsFor(data.activity, '{{ $series }}').length" x-cloak
                               class="absolute inset-0 grid place-items-center rounded-lg border border-dashed border-line text-xs text-fg-muted">No readings in this range</p>
                        </div>
                    </div>
                @endforeach
            </div>
        </x-card>

        <div class="mb-6 grid gap-6 xl:grid-cols-[minmax(0,1.7fr)_minmax(0,1fr)]">
            {{-- What needs somebody, worst first. Five at a time: a campus-wide
                 outage is twenty rows that all say the same, and the first
                 five, with the count of the rest, are what to act on. --}}
            <x-card flush>
                <x-slot:title>
                    Needs attention <span class="ml-1 font-normal tabular-nums text-fg-muted" x-text="data.incidents_open"></span>
                </x-slot:title>
                <x-slot:header>
                    <a :href="links.incidents" class="link inline-flex items-center gap-1 text-sm font-medium">
                        All incidents <x-icon name="arrow-right" class="size-3.5" />
                    </a>
                </x-slot:header>

                <p x-show="data.incidents.length > 1" class="border-b border-line bg-sunken/50 px-5 py-2 text-xs text-fg-muted" x-text="incidentSummary"></p>

                <ul class="divide-y divide-line" x-show="data.incidents.length">
                    <template x-for="incident in shownIncidents" :key="incident.id">
                        <li class="flex items-center gap-3 px-5 py-3">
                            <div class="flex min-w-0 flex-1 items-start gap-3">
                                <span class="mt-1.5 size-2 flex-none rounded-full" :class="incident.down ? 'bg-red-500' : 'bg-amber-500'" aria-hidden="true"></span>
                                <div class="min-w-0">
                                    <button type="button" x-show="incident.device_id" @click="inspect(incident.device_id, $event.currentTarget)"
                                            class="block max-w-full truncate text-left text-sm font-medium text-fg hover:text-brand-700" x-text="incident.title"></button>
                                    <p x-show="! incident.device_id" class="truncate text-sm font-medium text-fg" x-text="incident.title"></p>
                                    <p class="mt-0.5 flex flex-wrap gap-x-1.5 text-xs text-fg-muted">
                                        <span x-show="incident.place" x-text="incident.place + ' ·'"></span>
                                        <span class="tabular-nums" x-text="incident.for"></span>
                                        <span x-show="incident.behind" x-text="'· and ' + incident.behind + ' behind it'"></span>
                                        <template x-if="incident.ticket_number">
                                            <span>
                                                <span aria-hidden="true">·</span>
                                                <a x-show="links.ticket" :href="links.ticket?.replace('__ID__', incident.ticket_id)" class="link font-mono" x-text="incident.ticket_number"></a>
                                                <span x-show="! links.ticket" class="font-mono" x-text="incident.ticket_number"></span>
                                            </span>
                                        </template>
                                        <span aria-hidden="true">·</span>
                                        <span x-show="incident.acknowledged_by" class="text-fg" x-text="incident.acknowledged_by + ' has it'"></span>
                                        <span x-show="! incident.acknowledged_by" class="font-medium text-red-700">Nobody has it</span>
                                    </p>
                                </div>
                            </div>
                            <div class="flex flex-none items-center gap-2">
                                <template x-if="incident.can_acknowledge">
                                    <form method="POST" :action="links.acknowledge.replace('__ID__', incident.id)">
                                        @csrf
                                        <button type="submit" class="btn btn-primary btn-sm" :aria-label="'Take it: ' + incident.title">Take it</button>
                                    </form>
                                </template>
                                <button type="button" x-show="incident.device_id" class="btn btn-secondary btn-sm max-sm:hidden"
                                        @click="inspect(incident.device_id, $event.currentTarget)" :aria-label="'Look at ' + incident.subject">Look</button>
                                <a x-show="! incident.device_id" :href="links.incident.replace('__ID__', incident.id)" class="btn btn-secondary btn-sm">Open</a>
                            </div>
                        </li>
                    </template>
                </ul>

                <div x-show="data.incidents.length > firstIncidents || data.incidents_open > data.incidents.length" x-cloak
                     class="flex flex-wrap items-center justify-between gap-2 border-t border-line px-5 py-2.5 text-xs text-fg-muted">
                    <button type="button" x-show="data.incidents.length > firstIncidents" @click="allIncidents = ! allIncidents"
                            class="link font-medium" :aria-expanded="allIncidents.toString()"
                            x-text="allIncidents ? 'Show the first ' + firstIncidents : 'Show all ' + data.incidents.length"></button>
                    <span x-show="data.incidents_open > data.incidents.length"
                          x-text="(data.incidents_open - data.incidents.length) + ' more on the incidents screen'"></span>
                </div>

                <div x-show="! data.incidents.length" class="flex items-center gap-3 px-5 py-6">
                    <span class="grid size-9 flex-none place-items-center rounded-full bg-emerald-50 text-emerald-700" aria-hidden="true">
                        <x-icon name="check" class="size-4" />
                    </span>
                    <div>
                        <p class="font-medium text-fg">Nothing needs attention</p>
                        <p class="text-sm text-fg-muted" x-text="data.counts.stale ? data.counts.stale + ' devices are unknown, which raises no incident on its own.' : 'Every device that should answer does.'"></p>
                    </div>
                </div>
            </x-card>

            {{-- The estate by status. Each count is also the filter below. --}}
            <x-card>
                <x-slot:title>The estate now</x-slot:title>
                <x-slot:header>
                    <span class="text-xs tabular-nums text-fg-muted" x-text="data.counts.total + ' devices'"></span>
                </x-slot:header>

                <div class="flex h-2.5 overflow-hidden rounded-full bg-sunken" aria-hidden="true">
                    <template x-for="tab in tabs.slice(1)" :key="tab.key">
                        <span class="block h-full" :class="dot(tab.key)" :style="`width: ${data.counts.total ? tab.count / data.counts.total * 100 : 0}%`"></span>
                    </template>
                </div>

                <div class="mt-3 flex flex-wrap gap-1.5" role="group" aria-label="Filter by status">
                    <template x-for="tab in tabs" :key="tab.key">
                        <button type="button" @click="status = tab.key" :aria-pressed="(status === tab.key).toString()"
                                class="inline-flex h-7 items-center gap-1.5 rounded-lg border px-2.5 text-xs transition"
                                :class="status === tab.key ? 'border-brand-600 ring-1 ring-brand-600 text-fg' : 'border-line text-fg-muted hover:border-line-strong hover:text-fg'">
                            <span class="size-2 rounded-full" :class="dot(tab.key)" aria-hidden="true"></span>
                            <span x-text="tab.label"></span>
                            <span class="font-semibold tabular-nums text-fg" x-text="tab.count"></span>
                        </button>
                    </template>
                </div>

                <dl class="mt-4 grid grid-cols-3 gap-2">
                    <template x-for="kind in data.kinds" :key="kind.kind">
                        <div class="min-w-0 rounded-lg border border-line px-2.5 py-2">
                            <dt class="truncate text-xs text-fg-muted" x-text="kind.label"></dt>
                            <dd class="mt-0.5 text-lg font-semibold tabular-nums text-fg">
                                <span x-text="kind.up"></span><span class="text-sm font-medium text-fg-subtle" x-text="' of ' + kind.total + ' up'"></span>
                            </dd>
                        </div>
                    </template>
                </dl>

                <ul class="mt-4 space-y-1.5 text-xs" aria-label="Agents">
                    <template x-for="agent in data.agents" :key="agent.name">
                        <li class="flex items-center gap-2">
                            <span class="size-2 flex-none rounded-full" :class="agent.silent ? 'bg-red-500' : 'bg-emerald-500'" aria-hidden="true"></span>
                            <span class="font-medium text-fg" x-text="agent.name"></span>
                            <span class="text-fg-muted" x-text="agent.silent ? 'silent, last heard ' + agent.last_seen : 'reporting, ' + agent.last_seen"></span>
                        </li>
                    </template>
                </ul>
            </x-card>
        </div>

        {{-- Every device, where it is. --}}
        <x-card flush>
            <x-slot:title>Where</x-slot:title>

            <div class="flex flex-wrap items-center gap-2 border-b border-line px-5 py-3">
                    <label class="relative min-w-0 flex-1 sm:max-w-xs">
                        <span class="sr-only">Find a device or place</span>
                        <x-icon name="search" class="pointer-events-none absolute left-2.5 top-1/2 size-4 -translate-y-1/2 text-fg-subtle" />
                        <input type="search" x-model="search" placeholder="Find a device or place" class="h-8 w-full pl-8 text-sm">
                    </label>
                    <div class="inline-flex gap-0.5 rounded-lg bg-sunken p-0.5" role="group" aria-label="View">
                        <template x-for="option in [['places', 'By place'], ['list', 'List']]" :key="option[0]">
                            <button type="button" @click="view = option[0]" :aria-pressed="(view === option[0]).toString()"
                                    class="rounded-md px-3 py-1 text-xs font-medium transition"
                                    :class="view === option[0] ? 'bg-surface text-fg shadow-xs' : 'text-fg-muted hover:text-fg'" x-text="option[1]"></button>
                        </template>
                    </div>
            </div>

            {{-- By place: what each room's access points hang from, then the
                 access points. Devices outside the filter are dimmed, not
                 hidden, so a room never looks emptier than it is. --}}
            <div x-show="view === 'places'" class="grid gap-3 p-4 md:grid-cols-2 xl:grid-cols-3">
                <template x-for="place in shownPlaces" :key="place.id ?? 0">
                    <section class="rounded-xl border p-3.5" :class="place.worst === 0 ? 'border-red-300' : 'border-line'" :aria-label="place.name">
                        <div class="flex items-baseline justify-between gap-2">
                            <h3 class="truncate font-medium text-fg" x-text="place.name"></h3>
                            <span class="flex-none text-xs tabular-nums text-fg-muted" x-show="place.clients" x-text="place.clients + (place.clients === 1 ? ' client' : ' clients')"></span>
                        </div>

                        <div class="mt-2 flex flex-wrap items-center gap-1.5" x-show="upstreamOf(place).length">
                            <template x-for="device in upstreamOf(place)" :key="device.id">
                                <button type="button" @click="inspect(device.id, $event.currentTarget)"
                                        class="inline-flex h-6 items-center gap-1.5 rounded-md border border-line px-2 font-mono text-xs text-fg-muted hover:text-fg"
                                        :class="dimmed(device) && 'opacity-30'"
                                        :title="device.kind_label + ', ' + device.state_label">
                                    <span class="size-1.5 rounded-full" :class="device.fill" aria-hidden="true"></span>
                                    <span x-text="device.code"></span>
                                    <span class="sr-only" x-text="', ' + device.kind_label + ', ' + device.state_label"></span>
                                </button>
                            </template>
                        </div>

                        <div class="mt-2.5 grid grid-cols-2 gap-1.5 sm:grid-cols-4" x-show="accessPointsOf(place).length">
                            <template x-for="device in accessPointsOf(place)" :key="device.id">
                                <button type="button" @click="inspect(device.id, $event.currentTarget)"
                                        class="min-w-0 rounded-lg border px-2 pb-1.5 pt-2 text-left transition hover:bg-sunken"
                                        :class="[device.state === 'offline' ? 'border-red-300 bg-red-50' : 'border-line bg-surface', dimmed(device) && 'opacity-30']">
                                    <span class="mb-1.5 block h-1 rounded-full" :class="device.state === 'stale' ? 'border border-dashed border-slate-400' : device.fill" aria-hidden="true"></span>
                                    <span class="block truncate font-mono text-xs font-medium text-fg" x-text="device.code"></span>
                                    <span class="block text-xs tabular-nums" :class="device.state === 'offline' ? 'font-medium text-red-700' : 'truncate text-fg-muted'" x-text="tileFigure(device)"></span>
                                    <span class="sr-only" x-show="['online', 'degraded'].includes(device.state)" x-text="', ' + device.state_label"></span>
                                </button>
                            </template>
                        </div>
                    </section>
                </template>
                <p x-show="! shownPlaces.length" class="text-sm text-fg-muted md:col-span-2 xl:col-span-3">Nothing matches. Clear the search to see every place.</p>
            </div>

            {{-- The same devices as a list, worst first. --}}
            <div x-show="view === 'list'" x-cloak>
                <ul class="divide-y divide-line md:hidden" x-show="listed.length">
                    <template x-for="device in listed" :key="device.id">
                        <li>
                            <button type="button" @click="inspect(device.id, $event.currentTarget)" class="w-full px-5 py-3 text-left active:bg-sunken">
                                <span class="flex items-start justify-between gap-3">
                                    <span class="min-w-0">
                                        <span class="block font-mono text-sm font-medium text-fg" x-text="device.code"></span>
                                        <span class="block truncate text-xs text-fg-muted" x-text="device.kind_label + ' · ' + (device.location_name ?? 'No place set')"></span>
                                    </span>
                                    <span class="inline-flex h-6 flex-none items-center rounded-md px-2 text-xs font-medium ring-1 ring-inset" :class="device.badge" x-text="device.state_label"></span>
                                </span>
                                <span class="mt-1.5 flex flex-wrap gap-x-4 gap-y-1 text-xs tabular-nums text-fg-muted">
                                    <span x-show="device.for" x-text="'for ' + device.for"></span>
                                    <span x-show="device.clients !== null" x-text="device.clients + ' of ' + device.capacity + ' clients'"></span>
                                    <span x-show="device.latency_ms !== null" x-text="device.latency_ms + ' ms'"></span>
                                    <span x-show="device.loss_pct" x-text="device.loss_pct + '% loss'"></span>
                                </span>
                            </button>
                        </li>
                    </template>
                </ul>
                <div class="overflow-x-auto max-md:hidden" x-show="listed.length" tabindex="0" role="region" aria-label="Devices, scrollable">
                    <table class="min-w-full text-sm">
                        <thead class="border-b border-line text-left text-xs text-fg-muted">
                            <tr>
                                <th scope="col" class="h-10 px-5 font-medium">Device</th>
                                <th scope="col" class="h-10 px-4 font-medium">Place</th>
                                <th scope="col" class="h-10 px-4 font-medium">Status</th>
                                <th scope="col" class="h-10 px-4 text-right font-medium">Clients</th>
                                <th scope="col" class="h-10 px-4 text-right font-medium">Utilisation</th>
                                <th scope="col" class="h-10 px-4 text-right font-medium">Latency</th>
                                <th scope="col" class="h-10 px-4 text-right font-medium">Loss</th>
                                <th scope="col" class="h-10 px-4 font-medium">Incident</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            <template x-for="device in listed" :key="device.id">
                                <tr class="hover:bg-sunken/60">
                                    <td class="whitespace-nowrap px-5 py-2.5">
                                        <button type="button" @click="inspect(device.id, $event.currentTarget)" class="text-left">
                                            <span class="font-mono font-medium text-fg hover:text-brand-700" x-text="device.code"></span>
                                            <span class="ml-1 text-xs text-fg-subtle" x-text="device.kind_label"></span>
                                        </button>
                                    </td>
                                    <td class="whitespace-nowrap px-4 py-2.5 text-fg-muted" x-text="device.location_name ?? 'No place set'"></td>
                                    <td class="whitespace-nowrap px-4 py-2.5">
                                        <span class="inline-flex h-6 items-center rounded-md px-2 text-xs font-medium ring-1 ring-inset" :class="device.badge" x-text="device.state_label"></span>
                                        <span x-show="device.for" class="ml-1 text-xs tabular-nums text-fg-muted" x-text="'for ' + device.for"></span>
                                    </td>
                                    <td class="px-4 py-2.5 text-right tabular-nums">
                                        <span x-show="device.clients === null" class="text-fg-subtle">-</span>
                                        <span x-show="device.clients !== null"><span class="text-fg" x-text="device.clients"></span><span class="text-fg-subtle" x-text="'/' + device.capacity"></span></span>
                                    </td>
                                    <td class="px-4 py-2.5 text-right tabular-nums" :class="device.utilization === null ? 'text-fg-subtle' : 'text-fg'" x-text="device.utilization === null ? '-' : device.utilization + '%'"></td>
                                    <td class="px-4 py-2.5 text-right tabular-nums" :class="device.latency_ms === null ? 'text-fg-subtle' : 'text-fg'" x-text="device.latency_ms === null ? '-' : device.latency_ms + ' ms'"></td>
                                    <td class="px-4 py-2.5 text-right tabular-nums" :class="device.loss_pct === null ? 'text-fg-subtle' : 'text-fg'" x-text="device.loss_pct === null ? '-' : device.loss_pct + '%'"></td>
                                    <td class="whitespace-nowrap px-4 py-2.5">
                                        <a x-show="device.incident_id" :href="links.incident.replace('__ID__', device.incident_id)" class="link text-xs font-medium">Open incident</a>
                                        <span x-show="! device.incident_id" class="text-fg-subtle">-</span>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
                <div x-show="! listed.length">
                    <x-empty-state icon="wifi" title="No device matches this view"
                                   message="Try another status, or clear the search." />
                </div>
            </div>
        </x-card>

        {{-- One device, beside the page rather than over it. --}}
        <div x-show="detail" x-cloak class="fixed inset-0 z-40" aria-hidden="true">
            <div class="absolute inset-0 bg-black/20" @click="closeDevice()"></div>
        </div>
        <section x-show="detail" x-cloak
                 x-transition:enter="transition duration-150 ease-out" x-transition:enter-start="translate-x-full" x-transition:enter-end="translate-x-0"
                 class="fixed inset-y-0 right-0 z-50 w-full max-w-md overflow-y-auto border-l border-line bg-surface shadow-xl"
                 role="dialog" aria-modal="true" aria-labelledby="device-title"
                 @keydown.tab="keepFocusIn($el, $event)">
            <template x-if="detail">
                <div>
                    <div class="sticky top-0 border-b border-line bg-surface px-5 pb-4 pt-4">
                        <div class="flex items-center justify-between gap-3">
                            <p class="truncate text-xs text-fg-muted" x-text="detail.kind_label + (detail.location_name ? ' · ' + detail.location_name : '')"></p>
                            <button type="button" x-ref="closeDevice" @click="closeDevice()" class="btn-icon size-8 text-fg-muted" aria-label="Close">
                                <x-icon name="close" class="size-4" />
                            </button>
                        </div>
                        <div class="mt-1 flex flex-wrap items-center gap-2.5">
                            <h3 id="device-title" class="font-mono text-2xl font-semibold text-fg" x-text="detail.code"></h3>
                            <span class="inline-flex h-6 items-center rounded-md px-2 text-xs font-medium ring-1 ring-inset" :class="detail.badge" x-text="detail.state_label"></span>
                            <span x-show="detail.scenario" class="text-xs text-fg-muted" x-text="'Simulated: ' + detail.scenario_label"></span>
                        </div>
                        <p class="mt-1 text-xs text-fg-muted">
                            <span x-show="detail.for" class="tabular-nums" x-text="detail.state_label + ' for ' + detail.for + (detail.since ? ', since ' + detail.since : '')"></span>
                            <span x-show="! detail.for" x-text="'Last reading ' + (detail.last_seen ?? 'never')"></span>
                            <span x-show="detail.model" x-text="' · ' + detail.model"></span>
                            <span x-show="detail.ip_address"> · <span class="font-mono" x-text="detail.ip_address"></span></span>
                        </p>
                    </div>

                    <dl class="grid grid-cols-4 gap-2 border-b border-line px-5 py-4">
                        <template x-for="figure in figures(detail)" :key="figure[0]">
                            <div class="rounded-lg bg-sunken px-2.5 py-2">
                                <dt class="text-2xs text-fg-muted" x-text="figure[0]"></dt>
                                <dd class="mt-0.5 text-base font-semibold tabular-nums" :class="figure[1] === '-' ? 'text-fg-subtle' : 'text-fg'" x-text="figure[1]"></dd>
                            </div>
                        </template>
                    </dl>

                    <div class="border-b border-line px-5 py-4">
                        <h4 class="mb-2 text-xs font-medium text-fg-muted">Where the fault is</h4>
                        <p x-show="detailLoading" class="text-sm text-fg-muted">Loading</p>
                        <ol x-show="! detailLoading" class="space-y-1.5 text-sm">
                            <template x-for="hop in [...(detail.path ?? []), detail]" :key="hop.id">
                                <li class="flex items-center gap-2.5">
                                    <span class="size-2 flex-none rounded-full" :class="dot(hop.state)" aria-hidden="true"></span>
                                    <span class="font-mono" :class="hop.id === detail.id ? 'font-semibold text-fg' : 'text-fg'" x-text="hop.code"></span>
                                    <span class="text-xs text-fg-muted" x-text="hop.kind_label.toLowerCase() + ', ' + hop.state_label.toLowerCase()"></span>
                                </li>
                            </template>
                        </ol>
                        <p x-show="detail.verdict" class="mt-3 rounded-lg bg-sunken px-3 py-2.5 text-sm text-fg" x-text="detail.verdict"></p>
                    </div>

                    <template x-if="detail.incident">
                        <div class="border-b border-line px-5 py-4">
                            <h4 class="mb-1 text-xs font-medium text-fg-muted">Incident</h4>
                            <p class="font-medium text-fg" x-text="detail.incident.title"></p>
                            <p class="mt-0.5 text-xs">
                                <span x-show="detail.incident.acknowledged_by" class="text-fg" x-text="detail.incident.acknowledged_by + ' took it at ' + detail.incident.acknowledged_at"></span>
                                <span x-show="! detail.incident.acknowledged_by" class="font-medium text-red-700">Nobody has taken it yet</span>
                            </p>
                            <ol class="mt-3 space-y-1 text-sm" aria-label="Timeline">
                                <template x-for="(event, i) in detail.incident.events ?? []" :key="i">
                                    <li class="grid grid-cols-[3.25rem_1fr] gap-2">
                                        <span class="tabular-nums text-fg-muted" x-text="event.at"></span>
                                        <span class="text-fg" x-text="event.message"></span>
                                    </li>
                                </template>
                            </ol>
                            <div class="mt-3 flex flex-wrap gap-2">
                                <template x-if="detail.incident.can_acknowledge">
                                    <form method="POST" :action="links.acknowledge.replace('__ID__', detail.incident.id)">
                                        @csrf
                                        <button type="submit" class="btn btn-primary btn-sm">Take it</button>
                                    </form>
                                </template>
                                <a :href="links.incident.replace('__ID__', detail.incident.id)" class="btn btn-secondary btn-sm">Open incident</a>
                                <a x-show="detail.incident.ticket_id && links.ticket" :href="links.ticket?.replace('__ID__', detail.incident.ticket_id)"
                                   class="btn btn-secondary btn-sm" x-text="'Open ' + detail.incident.ticket_number"></a>
                            </div>
                        </div>
                    </template>

                    <div class="border-b border-line px-5 py-4">
                        <div class="mb-2 flex items-center justify-between gap-3">
                            <h4 class="text-xs font-medium text-fg-muted" x-text="detail.kind === 'access_point' ? 'Connected clients' : 'Latency, ms'"></h4>
                            @include('monitor.partials.range')
                        </div>
                        <div class="relative h-32">
                            {{-- An id, not x-ref: a ref inside x-if registers on
                                 the template's scope, not the page's. --}}
                            <canvas id="device-chart" role="img" :aria-label="(detail.kind === 'access_point' ? 'Connected clients' : 'Latency') + ' for ' + detail.code"></canvas>
                            <p x-show="! detailLoading && ! pointsFor(detail.activity ?? [], detail.kind === 'access_point' ? 'clients' : 'latency').length"
                               class="absolute inset-0 grid place-items-center text-xs text-fg-muted">No readings in this range.</p>
                        </div>
                    </div>

                    <div class="px-5 py-4 text-sm" x-show="links.history && detail.kind === 'access_point'">
                        <a :href="links.history?.replace('__ID__', detail.id)" class="link inline-flex items-center gap-1 font-medium">
                            Full history and every reading <x-icon name="arrow-right" class="size-3.5" />
                        </a>
                    </div>
                    <p x-show="detailFailed" class="px-5 py-4 text-sm text-red-700">Could not load this device. Try again.</p>
                </div>
            </template>
        </section>

        @if ($canSimulate)
            {{-- The simulation control room --}}
            <x-modal name="run-simulation" title="Simulation" maxWidth="lg">
                <div class="mb-5 flex items-center justify-between gap-4 rounded-lg border border-line px-3.5 py-3">
                    <div>
                        <p class="text-sm font-medium text-fg">Take a sample now</p>
                        <p class="text-xs text-fg-muted">One reading from every simulated access point, without waiting for the scheduler.</p>
                    </div>
                    <form method="POST" action="{{ route('monitor.sample') }}" x-data="{ busy: false }" @submit="busy = true">
                        @csrf
                        <button type="submit" :disabled="busy" class="btn btn-secondary btn-sm">
                            <x-icon name="lightning" class="size-3.5" />
                            <span x-text="busy ? 'Sampling' : 'Generate sample'">Generate sample</span>
                        </button>
                    </form>
                </div>

                <form method="POST" action="{{ route('monitor.simulate') }}" id="run-simulation-form" class="space-y-5"
                      x-data="{ busy: false }" @submit="busy = true">
                    @csrf

                    <p class="text-sm text-fg-muted">
                        Or change what the simulator reports. Nothing on a real network is touched, and the
                        system reacts through its ordinary rules: classification, incidents, tickets and alerts.
                    </p>

                    <x-form.select name="access_point_id" label="Target access point" blank="All simulated access points"
                                   :options="collect($data['devices'])->whereIn('id', $targets)->sortBy('code', SORT_NATURAL)->mapWithKeys(fn ($d) => [$d['id'] => $d['code'].($d['location'] ? ' · '.$d['location'] : '')])->all()" />

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
             * runs before Alpine starts: see the note in layouts/app.blade.php.
             *
             * Chart instances live in this closure, NOT in the returned object.
             * Alpine wraps its state in a Proxy, and a Chart.js instance inside
             * one recurses on every update.
             */
            function networkMonitor(initial, options) {
                const charts = {};
                let deviceChart = null;
                let opener = null;
                const params = new URLSearchParams(window.location.search);

                // A canvas cannot read a Tailwind class, so each series reads
                // the theme variable its own data-colour names. Read on every
                // draw, so a poll after the theme changes picks up the other
                // palette.
                const colour = (name, alpha) => window.themeToken(name, alpha);

                const draw = (canvas, points, key, tokenName, existing) => {
                    if (! canvas) return existing;
                    const pointRadius = points.length === 1 ? 3 : 0;
                    const data = {
                        labels: points.map((p) => p.label),
                        datasets: [{
                            data: points.map((p) => p[key]),
                            borderColor: colour(tokenName),
                            backgroundColor: colour(tokenName, 0.1),
                            borderWidth: 1.75, fill: true, tension: 0.35, pointRadius,
                            pointBackgroundColor: colour(tokenName), pointHoverRadius: Math.max(3, pointRadius),
                        }],
                    };

                    if (existing && existing.canvas === canvas) {
                        existing.data = data;
                        existing.update();
                        return existing;
                    }
                    existing?.destroy();

                    return new window.Chart(canvas, {
                        type: 'line',
                        data,
                        options: {
                            maintainAspectRatio: false,
                            interaction: { mode: 'index', intersect: false },
                            plugins: { legend: { display: false } },
                            scales: {
                                x: { grid: { display: false }, ticks: { autoSkip: true, maxTicksLimit: 4, maxRotation: 0 } },
                                y: { beginAtZero: true, ticks: { maxTicksLimit: 3 } },
                            },
                        },
                    });
                };

                const index = (devices) => Object.fromEntries(devices.map((d) => [d.id, d]));

                return {
                    data: initial,
                    // Devices by id, rebuilt when a poll replaces the data.
                    byId: index(initial.devices),
                    links: options.links,
                    status: params.get('status') ?? 'all',
                    view: params.get('view') === 'list' ? 'list' : 'places',
                    search: '',
                    range: '24h',
                    refreshing: false,
                    failing: false,
                    detail: null,
                    allIncidents: false,
                    firstIncidents: 5,
                    detailLoading: false,
                    detailFailed: false,

                    get tabs() {
                        const c = this.data.counts;
                        return [
                            { key: 'all', label: 'All', count: c.total },
                            { key: 'offline', label: 'Offline', count: c.offline },
                            { key: 'degraded', label: 'Degraded', count: c.degraded },
                            { key: 'stale', label: 'Unknown', count: c.stale },
                            { key: 'maintenance', label: 'Maintenance', count: c.maintenance },
                            { key: 'online', label: 'Online', count: c.online },
                        ];
                    },

                    get shownIncidents() {
                        return this.allIncidents ? this.data.incidents : this.data.incidents.slice(0, this.firstIncidents);
                    },

                    // "24 nobody has taken, 1 being handled. 23 down, 2 slow or
                    // unstable." The shape of an outage before its rows, over
                    // every open incident, not only the ones listed.
                    get incidentSummary() {
                        const open = this.data.incidents_open;
                        const untaken = this.data.incidents_untaken;
                        const down = this.data.incidents_down;
                        const parts = [];
                        if (untaken) parts.push(`${untaken} nobody has taken`);
                        if (open - untaken) parts.push(`${open - untaken} being handled`);
                        const kinds = [];
                        if (down) kinds.push(`${down} down`);
                        if (open - down) kinds.push(`${open - down} slow or unstable`);
                        return `${parts.join(', ')}. ${kinds.join(', ')}.`;
                    },

                    get agentSummary() {
                        const agents = this.data.agents;
                        if (! agents.length) return 'No agent watches a device yet';
                        const silent = agents.filter((a) => a.silent).length;
                        if (silent) return `${silent} of ${agents.length} ${agents.length === 1 ? 'agent' : 'agents'} silent`;
                        return `${agents.length} ${agents.length === 1 ? 'agent' : 'agents'} reporting`;
                    },


                    matches(device) {
                        const q = this.search.trim().toLowerCase();
                        return q === '' || `${device.code} ${device.name ?? ''} ${device.location ?? ''} ${device.location_name ?? ''}`.toLowerCase().includes(q);
                    },

                    dimmed(device) {
                        return this.status !== 'all' && device.state !== this.status;
                    },

                    get shownPlaces() {
                        const q = this.search.trim().toLowerCase();
                        return this.data.places.filter((place) => q === ''
                            || `${place.name} ${place.code ?? ''}`.toLowerCase().includes(q)
                            || place.device_ids.some((id) => this.byId[id] && this.matches(this.byId[id])));
                    },

                    upstreamOf(place) {
                        return place.device_ids.map((id) => this.byId[id]).filter((d) => d && d.kind !== 'access_point');
                    },

                    accessPointsOf(place) {
                        return place.device_ids.map((id) => this.byId[id]).filter((d) => d && d.kind === 'access_point');
                    },

                    get listed() {
                        return this.data.devices.filter((d) => (this.status === 'all' || d.state === this.status) && this.matches(d));
                    },

                    tileFigure(device) {
                        if (device.state === 'offline') return 'Offline' + (device.for ? ' ' + device.for : '');
                        if (device.state === 'stale') return 'Unknown';
                        if (device.state === 'maintenance') return 'Maintenance';
                        if (device.clients === null) return device.latency_ms === null ? '-' : device.latency_ms + ' ms';
                        // Degraded shows how full it is; the panel and the list
                        // have the rest.
                        return device.state === 'degraded'
                            ? `${device.clients} of ${device.capacity}`
                            : `${device.clients} ${device.clients === 1 ? 'client' : 'clients'}`;
                    },

                    dot(state) {
                        return {
                            all: 'bg-brand-500', online: 'bg-emerald-500', degraded: 'bg-amber-500',
                            offline: 'bg-red-500', maintenance: 'bg-slate-400', stale: 'bg-slate-300',
                        }[state] ?? 'bg-slate-300';
                    },

                    figures(device) {
                        const v = (x, unit = '') => x === null || x === undefined ? '-' : x + unit;
                        return [
                            ['Clients', device.clients === null || device.clients === undefined ? '-' : `${device.clients}/${device.capacity}`],
                            ['Utilisation', v(device.utilization, '%')],
                            ['Latency', v(device.latency_ms, ' ms')],
                            ['Loss', v(device.loss_pct, '%')],
                        ];
                    },

                    pointsFor(series, key) {
                        const hours = { '1h': 1, '6h': 6, '24h': 24 }[this.range];
                        const since = Date.now() - hours * 3600 * 1000;
                        return series.filter((p) => new Date(p.at).getTime() >= since && p[key] !== null && p[key] !== undefined);
                    },

                    latest(key) {
                        const points = this.pointsFor(this.data.activity, key);
                        return points.length ? points[points.length - 1][key] : null;
                    },

                    start() {
                        this.drawActivity();
                        this.$watch('range', () => { this.drawActivity(); this.drawDevice(); });
                        this.$watch('status', () => this.syncUrl());
                        this.$watch('view', () => this.syncUrl());

                        const wanted = params.get('device');
                        const device = wanted && this.data.devices.find((d) => d.code === wanted);
                        if (device) this.inspect(device.id);

                        window.pollWhileVisible(() => this.refresh(), options.interval);
                    },

                    async refresh() {
                        this.refreshing = true;
                        try {
                            this.data = await window.fetchJson(options.endpoint);
                            this.byId = index(this.data.devices);
                            this.failing = false;
                            this.drawActivity();
                            if (this.detail) this.load(this.detail.id);
                        } catch (e) {
                            // Keep the last good numbers and say so, as the dashboard does.
                            this.failing = true;
                        } finally {
                            this.refreshing = false;
                        }
                    },

                    drawActivity() {
                        for (const key of ['clients', 'throughput', 'latency']) {
                            const canvas = this.$refs[key];
                            charts[key] = draw(canvas, this.pointsFor(this.data.activity, key), key, canvas?.dataset.colour, charts[key]);
                        }
                    },

                    drawDevice() {
                        const canvas = document.getElementById('device-chart');
                        if (! this.detail?.activity || ! canvas) return;
                        const key = this.detail.kind === 'access_point' ? 'clients' : 'latency';
                        deviceChart = draw(canvas, this.pointsFor(this.detail.activity, key), key, key === 'clients' ? 'c-brand-500' : 'c-amber-500', deviceChart);
                    },

                    async inspect(id, from = null) {
                        opener = from ?? document.activeElement;
                        this.detail = { ...this.byId[id], path: [] };
                        this.detailFailed = false;
                        this.syncUrl();
                        await this.$nextTick();
                        this.$refs.closeDevice?.focus();
                        await this.load(id);
                    },

                    async load(id) {
                        this.detailLoading = ! this.detail?.verdict;
                        try {
                            const fresh = await window.fetchJson(options.deviceEndpoint.replace('__ID__', id));
                            if (this.detail?.id !== id) return;
                            this.detail = fresh;
                            await this.$nextTick();
                            this.drawDevice();
                        } catch (e) {
                            this.detailFailed = true;
                        } finally {
                            this.detailLoading = false;
                        }
                    },

                    // Tab and Shift+Tab go round the panel's own controls, as
                    // aria-modal promises, instead of on to the page behind.
                    keepFocusIn(panel, event) {
                        const stops = [...panel.querySelectorAll('a[href], button:not([disabled]), input, select, textarea, [tabindex]:not([tabindex="-1"])')]
                            .filter((el) => el.offsetParent !== null);
                        if (! stops.length) return;
                        const first = stops[0];
                        const last = stops[stops.length - 1];
                        if (event.shiftKey && document.activeElement === first) {
                            event.preventDefault();
                            last.focus();
                        } else if (! event.shiftKey && document.activeElement === last) {
                            event.preventDefault();
                            first.focus();
                        }
                    },

                    closeDevice() {
                        if (! this.detail) return;
                        this.detail = null;
                        deviceChart?.destroy();
                        deviceChart = null;
                        this.syncUrl();
                        opener?.focus?.();
                    },

                    syncUrl() {
                        const url = new URL(window.location.href);
                        const set = (name, value, fallback) => value === fallback ? url.searchParams.delete(name) : url.searchParams.set(name, value);
                        set('status', this.status, 'all');
                        set('view', this.view, 'places');
                        set('device', this.detail?.code ?? null, null);
                        window.history.replaceState(null, '', url);
                    },
                };
            }
        </script>
    @endpush
</x-app-layout>
