<x-app-layout>
    <x-slot name="title">Traffic</x-slot>

    {{-- Q9 asked whether this meant per-AP history or campus-wide trends. Both,
         from one screen: unfiltered is the campus trend, filtered to one access
         point is its history. Two screens reading the same table would have
         been two things to keep in step. --}}

    @php
        $today = \App\Support\CampusClock::today();
        $ranges = [
            'Today' => [$today->toDateString(), $today->toDateString()],
            'Last 7 days' => [$today->copy()->subDays(6)->toDateString(), $today->toDateString()],
            'Last 30 days' => [$today->copy()->subDays(29)->toDateString(), $today->toDateString()],
        ];
        $fromValue = $from->campus()->toDateString();
        $toValue = $to->campus()->toDateString();
        $scope = array_filter(request()->only(['access_point', 'location']), 'filled');

        $clients = collect($trend['clients'] ?? [])->filter(fn ($v) => $v !== null);
        $latency = collect($trend['latency'] ?? [])->filter(fn ($v) => $v !== null);
    @endphp

    <x-page-header title="Traffic and congestion"
                   :subtitle="$from->campus()->format('j M Y').' to '.$to->campus()->format('j M Y')" />

    {{-- The filters, as one toolbar. Quick ranges first, because "this week"
         is what people want far more often than two exact dates. --}}
    <form method="GET" class="mb-6 flex flex-wrap items-end gap-3 rounded-xl border border-line bg-surface p-4 shadow-xs">
        <div class="flex flex-wrap gap-1.5 self-center">
            @foreach ($ranges as $label => [$rangeFrom, $rangeTo])
                @php($on = $fromValue === $rangeFrom && $toValue === $rangeTo)
                <a href="{{ route('engineer.traffic.index', [...$scope, 'from' => $rangeFrom, 'to' => $rangeTo]) }}"
                   @class([
                       'inline-flex h-8 items-center rounded-full px-3 text-xs font-medium transition',
                       'bg-brand-600 text-white' => $on,
                       'bg-sunken text-fg-muted hover:text-fg' => ! $on,
                   ])>{{ $label }}</a>
            @endforeach
        </div>

        <span class="hidden h-8 w-px bg-line sm:block" aria-hidden="true"></span>

        <div class="w-36">
            <x-form.input name="from" label="From" type="date" :value="$fromValue" />
        </div>
        <div class="w-36">
            <x-form.input name="to" label="To" type="date" :value="$toValue" />
        </div>
        <div class="w-40">
            <x-form.select name="access_point" label="Access point" blank="All" :options="$accessPoints" :selected="request('access_point')" />
        </div>
        <div class="w-52">
            <x-form.select name="location" label="Location" blank="Anywhere" :options="$locations" :selected="request('location')" />
        </div>
        <div class="flex gap-2">
            <x-button type="submit" variant="secondary">Apply</x-button>
            @if (request()->hasAny(['from', 'to', 'access_point', 'location']))
                <x-button variant="ghost" :href="route('engineer.traffic.index')">Clear</x-button>
            @endif
        </div>
    </form>

    <div class="mb-6 grid grid-cols-2 gap-3 lg:grid-cols-4">
        <x-stat-tile label="Busiest hour" icon="users" :value="$clients->max() ?? '-'" unit="clients" />
        <x-stat-tile label="Typical load" icon="chart-line" :value="$clients->isEmpty() ? '-' : (int) round($clients->avg())" unit="clients" />
        <x-stat-tile label="Mean latency" icon="gauge" :value="$latency->isEmpty() ? '-' : (int) round($latency->avg())" unit="ms"
                     :tone="$latency->isNotEmpty() && $latency->avg() > config('atlas.thresholds.latency_ms') ? 'urgent' : null" />
        <x-stat-tile label="Worst hour" icon="warning" :value="$latency->max() === null ? '-' : (int) round($latency->max())" unit="ms"
                     :tone="$latency->isNotEmpty() && $latency->max() > config('atlas.thresholds.latency_ms') ? 'urgent' : null"
                     :delta="$latency->isNotEmpty() && $latency->max() > config('atlas.thresholds.latency_ms') ? 'Above the '.config('atlas.thresholds.latency_ms').' ms threshold' : null" />
    </div>

    <x-card title="Campus load" subtitle="Total clients and mean latency per hour." class="mb-6">
        @if (empty($trend['labels']))
            <x-empty-state title="Nothing in this range"
                           message="No readings arrived in this range. Widen the date range, or check that the scheduler is running."
                           icon="chart" />
        @else
            <x-chart type="line" :height="280"
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

    <x-card title="Hour by hour" subtitle="Each access point's hourly bucket, newest first." flush>
        @if ($rows->isEmpty())
            <x-empty-state title="No readings in this range"
                           message="Try a wider date range, or check that the scheduler is running."
                           icon="chart" />
        @else
            <div class="relative overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="border-b border-line bg-sunken/60 text-left text-xs font-medium text-fg-muted">
                        <tr>
                            <th scope="col" class="h-10 px-5 font-medium">Hour</th>
                            <th scope="col" class="h-10 px-4 font-medium">Access point</th>
                            <th scope="col" class="h-10 px-4 text-right font-medium">Peak clients</th>
                            <th scope="col" class="h-10 px-4 text-right font-medium">Mean latency</th>
                            <th scope="col" class="h-10 px-4 text-right font-medium">Loss</th>
                            <th scope="col" class="h-10 px-4 font-medium">Utilisation</th>
                            <th scope="col" class="h-10 px-5 text-right font-medium">Uptime</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line text-fg">
                        @foreach ($rows as $row)
                            @php($util = (float) $row->avg_channel_util_pct)
                            <tr class="hover:bg-sunken/60">
                                <td class="whitespace-nowrap px-5 py-3 text-fg-muted">{{ $row->hour_bucket->campus()->format('j M, H:i') }}</td>
                                <td class="whitespace-nowrap px-4 py-3">
                                    @if ($row->device?->trashed())
                                        {{-- Removed from the inventory: its history stays, its page does not. --}}
                                        <span class="font-mono font-medium text-fg-muted">{{ $row->device->code }}</span>
                                        <span class="block text-xs text-fg-subtle">Removed</span>
                                    @else
                                        <a href="{{ route('engineer.aps.show', $row->network_device_id) }}"
                                           class="font-mono font-medium text-fg hover:text-brand-700">{{ $row->device?->code }}</a>
                                        <span class="block text-xs text-fg-subtle">{{ $row->device?->location?->code }}</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-right tabular-nums">{{ $row->peak_client_count }}</td>
                                <td @class([
                                    'px-4 py-3 text-right tabular-nums',
                                    'font-medium text-red-700' => $row->avg_latency_ms > config('atlas.thresholds.latency_ms'),
                                ])>{{ $row->avg_latency_ms ? number_format((float) $row->avg_latency_ms, 0).' ms' : '-' }}</td>
                                <td @class([
                                    'px-4 py-3 text-right tabular-nums',
                                    'font-medium text-red-700' => (float) $row->avg_packet_loss_pct > config('atlas.thresholds.packet_loss_pct'),
                                ])>{{ number_format((float) $row->avg_packet_loss_pct, 1) }}%</td>
                                <td class="px-4 py-3">
                                    <span class="flex w-28 items-center gap-2">
                                        <span class="h-1.5 flex-1 overflow-hidden rounded-full bg-sunken">
                                            <span @class(['block h-full rounded-full', 'bg-amber-500' => $util >= config('atlas.thresholds.channel_util_pct'), 'bg-brand-500' => $util < config('atlas.thresholds.channel_util_pct')])
                                                  style="width: {{ (int) min(100, round($util)) }}%"></span>
                                        </span>
                                        <span class="w-9 text-right text-xs tabular-nums text-fg-muted">{{ number_format($util, 0) }}%</span>
                                    </span>
                                </td>
                                <td @class([
                                    'px-5 py-3 text-right tabular-nums',
                                    'font-medium text-red-700' => (float) $row->uptime_pct < 100,
                                ])>{{ number_format((float) $row->uptime_pct, 0) }}%</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if ($rows->hasPages())
                <x-slot:footer>
                    <x-pagination :paginator="$rows" />
                </x-slot:footer>
            @endif
        @endif
    </x-card>
</x-app-layout>
