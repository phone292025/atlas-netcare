<x-app-layout>
    <x-slot name="title">{{ $accessPoint->ap_code }}</x-slot>

    <x-page-header :title="$accessPoint->ap_code"
                   :subtitle="$accessPoint->location?->label() ?? 'No location'">
        <x-slot:actions>
            <x-status-badge :status="$accessPoint->status" />
            <a href="{{ route('engineer.dashboard') }}"
               class="rounded-md bg-white px-3 py-2 text-sm font-medium text-slate-700 ring-1 ring-slate-300 hover:bg-slate-50">
                Back to dashboard
            </a>
        </x-slot:actions>
    </x-page-header>

    <div class="mb-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <x-stat-tile label="Clients now" :value="$latest?->client_count ?? 0"
                     :unit="'of '.$accessPoint->client_capacity" />
        <x-stat-tile label="Latency" :value="$latest?->latency_ms ?? '—'" unit="ms"
                     :tone="$latest && $latest->latency_ms > config('atlas.thresholds.latency_ms') ? 'urgent' : 'healthy'" />
        <x-stat-tile label="Packet loss" :value="$latest ? number_format((float) $latest->packet_loss_pct, 1) : '—'" unit="%"
                     :tone="$latest && (float) $latest->packet_loss_pct > config('atlas.thresholds.packet_loss_pct') ? 'urgent' : 'healthy'" />
        <x-stat-tile label="Last seen" :value="$accessPoint->last_seen_at?->diffForHumans() ?? 'Never'" />
    </div>

    {{-- 24 hours from `telemetry_hourly` — 24 rows, not the 1,440 raw readings
         the same period holds. NFR-02 asks for this under two seconds, and
         charting off the raw table is slow enough to notice during a demo. --}}
    <x-card title="Last 24 hours" subtitle="Peak clients and mean latency, hour by hour." class="mb-4">
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

    <div class="grid gap-4 lg:grid-cols-3">
        <x-card title="Device" class="lg:col-span-1">
            <dl class="space-y-3 text-sm">
                <div>
                    <dt class="text-slate-500">Model</dt>
                    <dd class="mt-0.5 text-slate-900">{{ $accessPoint->model ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-slate-500">MAC address</dt>
                    <dd class="mt-0.5 font-mono text-slate-900">{{ $accessPoint->mac_address }}</dd>
                </div>
                <div>
                    <dt class="text-slate-500">IP address</dt>
                    <dd class="mt-0.5 font-mono text-slate-900">{{ $accessPoint->ip_address ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-slate-500">Capacity</dt>
                    <dd class="mt-0.5 text-slate-900">{{ $accessPoint->client_capacity }} clients</dd>
                </div>
            </dl>
        </x-card>

        <x-card title="Hourly detail" subtitle="What the chart above is drawn from." class="lg:col-span-2" flush>
            @if ($hours->isEmpty())
                <x-empty-state title="Nothing aggregated yet" icon="chart" />
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                            <tr>
                                <th scope="col" class="px-4 py-2.5 text-left font-medium">Hour</th>
                                <th scope="col" class="px-4 py-2.5 text-right font-medium">Peak clients</th>
                                <th scope="col" class="px-4 py-2.5 text-right font-medium">Latency</th>
                                <th scope="col" class="px-4 py-2.5 text-right font-medium">Loss</th>
                                <th scope="col" class="px-4 py-2.5 text-right font-medium">Uptime</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 text-slate-700">
                            @foreach ($hours->sortByDesc('hour_bucket') as $hour)
                                <tr>
                                    <td class="whitespace-nowrap px-4 py-2.5">{{ $hour->hour_bucket->campus()->format('j M H:i') }}</td>
                                    <td class="px-4 py-2.5 text-right tabular-nums">{{ $hour->peak_client_count }}</td>
                                    <td class="px-4 py-2.5 text-right tabular-nums">
                                        {{ $hour->avg_latency_ms ? number_format((float) $hour->avg_latency_ms, 0).' ms' : '—' }}
                                    </td>
                                    <td class="px-4 py-2.5 text-right tabular-nums">{{ number_format((float) $hour->avg_packet_loss_pct, 1) }}%</td>
                                    <td class="px-4 py-2.5 text-right tabular-nums
                                               {{ (float) $hour->uptime_pct < 100 ? 'font-medium text-red-700' : '' }}">
                                        {{ number_format((float) $hour->uptime_pct, 0) }}%
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-card>
    </div>
</x-app-layout>
