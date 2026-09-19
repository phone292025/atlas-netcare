<x-app-layout>
    <x-slot name="title">Traffic logs</x-slot>

    {{-- Q9 asked whether this meant per-AP history or campus-wide trends. Both,
         from one screen: unfiltered is the campus trend, filtered to one access
         point is its history. Two screens reading the same table would have
         been two things to keep in step. --}}

    <x-page-header title="Traffic and congestion"
                   :subtitle="$from->campus()->format('j M Y').' to '.$to->campus()->format('j M Y')" />

    <x-card title="Campus load" subtitle="Total clients and mean latency per hour." class="mb-4">
        @if (empty($trend['labels']))
            <x-empty-state title="Nothing in this range"
                           message="Telemetry is rolled into hourly buckets by atlas:aggregate-telemetry. Widen the date range, or run the collector and aggregator."
                           icon="chart" />
        @else
            <x-chart type="line" :height="260"
                     label="Total connected clients and mean latency per hour across the campus"
                     :data="[
                         'labels' => $trend['labels'],
                         'datasets' => [
                             [
                                 'label' => 'Clients',
                                 'data' => $trend['clients'],
                                 'borderColor' => \App\Support\Tone::chart('neutral'),
                                 'backgroundColor' => \App\Support\Tone::chart('neutral').'22',
                                 'fill' => true,
                                 'tension' => 0.3,
                                 'yAxisID' => 'y',
                             ],
                             [
                                 'label' => 'Mean latency (ms)',
                                 'data' => $trend['latency'],
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

    <x-data-table :headers="['Hour', 'Access point', 'Peak clients', 'Mean latency', 'Loss', 'Utilisation', 'Uptime']">
        <x-slot:filters>
            <form method="GET" class="flex flex-wrap items-end gap-2">
                <div class="w-40">
                    <x-form.input name="from" label="From" type="date" :value="$from->campus()->toDateString()" />
                </div>
                <div class="w-40">
                    <x-form.input name="to" label="To" type="date" :value="$to->campus()->toDateString()" />
                </div>
                <div class="w-44">
                    <x-form.select name="access_point" label="Access point" blank="All" :options="$accessPoints" :selected="request('access_point')" />
                </div>
                <div class="w-48">
                    <x-form.select name="location" label="Location" blank="Anywhere" :options="$locations" :selected="request('location')" />
                </div>
                <button type="submit" class="rounded-md bg-white px-3 py-2 text-sm font-medium text-slate-700 ring-1 ring-slate-300 hover:bg-slate-50">Apply</button>
                @if (request()->hasAny(['from', 'to', 'access_point', 'location']))
                    <a href="{{ route('engineer.traffic.index') }}" class="px-2 py-2 text-sm text-slate-500 underline">Clear</a>
                @endif
            </form>
        </x-slot:filters>

        @foreach ($rows as $row)
            <tr class="hover:bg-slate-50">
                <td class="whitespace-nowrap px-4 py-3">{{ $row->hour_bucket->campus()->format('j M H:i') }}</td>
                <td class="whitespace-nowrap px-4 py-3">
                    <a href="{{ route('engineer.aps.show', $row->access_point_id) }}"
                       class="font-medium text-slate-900 hover:text-brand-700">{{ $row->accessPoint?->ap_code }}</a>
                    <span class="block text-xs text-slate-400">{{ $row->accessPoint?->location?->code }}</span>
                </td>
                <td class="px-4 py-3 tabular-nums">{{ $row->peak_client_count }}</td>
                <td class="px-4 py-3 tabular-nums {{ $row->avg_latency_ms > config('atlas.thresholds.latency_ms') ? 'font-medium text-red-700' : '' }}">
                    {{ $row->avg_latency_ms ? number_format((float) $row->avg_latency_ms, 0).' ms' : '—' }}
                </td>
                <td class="px-4 py-3 tabular-nums {{ (float) $row->avg_packet_loss_pct > config('atlas.thresholds.packet_loss_pct') ? 'font-medium text-red-700' : '' }}">
                    {{ number_format((float) $row->avg_packet_loss_pct, 1) }}%
                </td>
                <td class="px-4 py-3 tabular-nums">{{ number_format((float) $row->avg_channel_util_pct, 0) }}%</td>
                <td class="px-4 py-3 tabular-nums {{ (float) $row->uptime_pct < 100 ? 'font-medium text-red-700' : '' }}">
                    {{ number_format((float) $row->uptime_pct, 0) }}%
                </td>
            </tr>
        @endforeach

        <x-slot:empty>
            <x-empty-state title="No readings in this range"
                           message="Try a wider date range, or run atlas:collect-telemetry and atlas:aggregate-telemetry."
                           icon="chart" />
        </x-slot:empty>

        <x-slot:pagination><x-pagination :paginator="$rows" /></x-slot:pagination>
    </x-data-table>
</x-app-layout>
