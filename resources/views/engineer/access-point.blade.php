<x-app-layout>
    <x-slot name="title">{{ $accessPoint->code }}</x-slot>

    @php
        $latencyHigh = $latest && $latest->latency_ms > config('atlas.thresholds.latency_ms');
        $lossHigh = $latest && (float) $latest->packet_loss_pct > config('atlas.thresholds.packet_loss_pct');
    @endphp

    <a href="{{ route('engineer.aps.index') }}"
       class="mb-4 inline-flex items-center gap-1.5 text-sm text-fg-muted hover:text-fg">
        <x-icon name="arrow-left" class="size-4" /> Access points
    </a>

    <x-page-header :title="$accessPoint->code"
                   :subtitle="$accessPoint->location?->label() ?? 'No location'">
        <x-slot:actions>
            <x-status-badge :status="$accessPoint->status" />
        </x-slot:actions>
    </x-page-header>

    <div class="mb-6 grid grid-cols-2 gap-3 lg:grid-cols-4">
        <x-stat-tile label="Clients now" icon="users" :value="$latest?->client_count ?? 0"
                     :unit="'of '.$accessPoint->client_capacity" />
        <x-stat-tile label="Latency" icon="gauge" :value="$latest?->latency_ms ?? '-'" unit="ms"
                     :tone="$latencyHigh ? 'urgent' : null"
                     :delta="$latencyHigh ? 'Above the '.config('atlas.thresholds.latency_ms').' ms threshold' : null" />
        <x-stat-tile label="Packet loss" icon="activity" :value="$latest ? number_format((float) $latest->packet_loss_pct, 1) : '-'" unit="%"
                     :tone="$lossHigh ? 'urgent' : null"
                     :delta="$lossHigh ? 'Above the '.config('atlas.thresholds.packet_loss_pct').'% threshold' : null" />
        <x-stat-tile label="Last seen" icon="clock" :value="$accessPoint->last_seen_at?->diffForHumans(['short' => true]) ?? 'Never'" />
    </div>

    @if ($openTickets->isNotEmpty())
        <x-alert type="warning" class="mb-6"
                 :title="$openTickets->count().' open '.Str::plural('ticket', $openTickets->count()).' on this access point'">
            <ul class="mt-1 space-y-1">
                @foreach ($openTickets as $ticket)
                    <li>
                        <a href="{{ route('engineer.tickets.show', $ticket) }}" class="font-medium underline-offset-2 hover:underline">
                            <span class="font-mono">{{ $ticket->ticket_number }}</span> {{ $ticket->title }}
                        </a>
                        <span class="opacity-80">· {{ $ticket->status->label() }}, {{ $ticket->assignee?->name ?? 'nobody on it yet' }}</span>
                    </li>
                @endforeach
            </ul>
        </x-alert>
    @endif

    <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_20rem]">
        <div class="min-w-0 space-y-6">
            {{-- 24 hours from `telemetry_hourly`: 24 rows, not the 1,440 raw
                 readings the same period holds. NFR-02 asks for this under two
                 seconds, and charting off the raw table is slow enough to
                 notice during a demo. --}}
            <x-card title="Last 24 hours" subtitle="Peak clients and mean latency, hour by hour.">
                @if ($hours->isEmpty())
                    <x-empty-state title="No history yet"
                                   message="Readings are rolled into hourly buckets by atlas:aggregate-telemetry. Run the collector and the aggregator, and this fills in."
                                   icon="chart" />
                @else
                    <x-chart type="line" :height="280"
                             label="Peak clients and mean latency over the last 24 hours"
                             :data="[
                                 'labels' => $chart['labels'],
                                 'datasets' => [
                                     [
                                         'label' => 'Peak clients',
                                         'data' => $chart['clients'],
                                         'borderColor' => \App\Support\Tone::chart('neutral'),
                                         'backgroundColor' => \App\Support\Tone::chart('neutral').'22',
                                         'fill' => true,
                                         'tension' => 0.3,
                                         'yAxisID' => 'y',
                                     ],
                                     [
                                         'label' => 'Mean latency (ms)',
                                         'data' => $chart['latency'],
                                         'borderColor' => \App\Support\Tone::chart('attention'),
                                         'tension' => 0.3,
                                         'yAxisID' => 'y1',
                                     ],
                                 ],
                             ]"
                             :options="[
                                 'scales' => [
                                     'y' => ['position' => 'left', 'title' => ['display' => true, 'text' => 'Clients']],
                                     'y1' => [
                                         'position' => 'right',
                                         'title' => ['display' => true, 'text' => 'Latency (ms)'],
                                         'grid' => ['drawOnChartArea' => false],
                                     ],
                                 ],
                             ]" />
                @endif
            </x-card>

            <x-card title="Hourly detail" subtitle="What the chart above is drawn from, newest first." flush>
                @if ($hours->isEmpty())
                    <x-empty-state title="Nothing aggregated yet" icon="chart" />
                @else
                    <div class="max-h-[26rem] overflow-auto">
                        <table class="min-w-full text-sm">
                            <thead class="sticky top-0 border-b border-line bg-sunken text-left text-xs font-medium text-fg-muted">
                                <tr>
                                    <th scope="col" class="h-10 px-5 font-medium">Hour</th>
                                    <th scope="col" class="h-10 px-4 text-right font-medium">Peak clients</th>
                                    <th scope="col" class="h-10 px-4 text-right font-medium">Latency</th>
                                    <th scope="col" class="h-10 px-4 text-right font-medium">Loss</th>
                                    <th scope="col" class="h-10 px-5 text-right font-medium">Uptime</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-line text-fg">
                                @foreach ($hours->sortByDesc('hour_bucket') as $hour)
                                    <tr class="hover:bg-sunken/60">
                                        <td class="whitespace-nowrap px-5 py-2.5 text-fg-muted">{{ $hour->hour_bucket->campus()->format('j M, H:i') }}</td>
                                        <td class="px-4 py-2.5 text-right tabular-nums">{{ $hour->peak_client_count }}</td>
                                        <td class="px-4 py-2.5 text-right tabular-nums">
                                            {{ $hour->avg_latency_ms ? number_format((float) $hour->avg_latency_ms, 0).' ms' : '-' }}
                                        </td>
                                        <td class="px-4 py-2.5 text-right tabular-nums">{{ number_format((float) $hour->avg_packet_loss_pct, 1) }}%</td>
                                        <td @class([
                                            'px-5 py-2.5 text-right tabular-nums',
                                            'font-medium text-red-700' => (float) $hour->uptime_pct < 100,
                                        ])>{{ number_format((float) $hour->uptime_pct, 0) }}%</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-card>
        </div>

        <x-card title="Device" class="self-start">
            <dl class="divide-y divide-line text-sm">
                @foreach ([
                    'Model' => [$accessPoint->model ?? '-', false],
                    'MAC address' => [$accessPoint->mac_address, true],
                    'IP address' => [$accessPoint->ip_address ?? '-', true],
                    'Capacity' => [$accessPoint->client_capacity.' clients', false],
                    'Location' => [$accessPoint->location?->label() ?? 'No location', false],
                ] as $term => [$value, $mono])
                    <div class="flex items-baseline justify-between gap-4 py-2.5 first:pt-0 last:pb-0">
                        <dt class="flex-none text-fg-muted">{{ $term }}</dt>
                        <dd @class(['min-w-0 truncate text-right text-fg', 'font-mono text-[13px]' => $mono])>{{ $value }}</dd>
                    </div>
                @endforeach
            </dl>
        </x-card>
    </div>
</x-app-layout>
