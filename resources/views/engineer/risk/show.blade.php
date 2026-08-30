@php
    $isPc = $type === 'lab-pc';
    $name = $isPc ? $asset->asset_tag : $asset->ap_code;
@endphp

<x-app-layout>
    <x-slot name="title">{{ $name }} risk</x-slot>

    {{-- FR-310. The screen that turns a score into analytics.

         A bare number could have come from a threshold rule. The breakdown
         below says WHY, and the chart says whether it is getting worse — which
         together are the difference between monitoring and prediction, and the
         reason ADR-0007 chose a weighted model over a trained one.

         @see docs/decisions/ADR-0007-weighted-risk-not-ml.md --}}

    <x-page-header :title="$name"
                   :subtitle="($isPc ? $asset->hostname : $asset->model).' · '.($asset->location?->label() ?? 'No location')">
        <x-slot:actions>
            <a href="{{ route('engineer.risk.index') }}"
               class="rounded-md bg-white px-3 py-2 text-sm font-medium text-slate-700 ring-1 ring-slate-300 hover:bg-slate-50">
                Back to ranking
            </a>

            {{-- FR-311. Preventive, not reactive: every other ticket in the
                 system is raised because something broke. --}}
            <a href="{{ route('assistant.tickets.create', ['asset' => $type, 'asset_id' => $asset->id]) }}"
               class="rounded-md bg-brand-600 px-3 py-2 text-sm font-medium text-white hover:bg-brand-700">
                Raise preventive ticket
            </a>
        </x-slot:actions>
    </x-page-header>

    @if (! $latest)
        <x-card>
            <x-empty-state title="This asset has never been scored"
                           message="Risk scores are written by php artisan atlas:recalculate-risk, which runs daily at 02:00."
                           icon="wrench" />
        </x-card>
    @else
        <div class="grid gap-4 lg:grid-cols-3">

            <x-card title="Current risk"
                    :subtitle="'Calculated '.$latest->calculated_at->diffForHumans()">
                @if (empty($latest->factors))
                    {{-- An empty breakdown is how the scorer reports "nothing
                         could be measured". Saying so matters: an access point
                         with no telemetry is usually a dead one, and showing it
                         as a clean 0 would be exactly backwards. --}}
                    <x-alert type="warning" title="Not enough data to score this">
                        No measurable factors. For an access point that usually means no
                        telemetry has been collected, which is itself worth investigating.
                    </x-alert>
                @else
                    <x-risk-gauge :score="$latest->score" :band="$latest->band" :factors="$latest->factors" />

                    <p class="mt-4 text-xs text-slate-500">
                        Each factor is a measurement divided by a ceiling, clamped to its
                        share, and weighted. The parts add up to the total.
                    </p>
                @endif
            </x-card>

            <x-card title="Risk over time"
                    subtitle="Ninety days. A score climbing is the prediction."
                    class="lg:col-span-2">
                @if ($history->count() < 2)
                    <x-empty-state title="Not enough history to draw a trend"
                                   message="A trend needs at least two runs. The daily job builds this up on its own."
                                   icon="chart" />
                @else
                    <x-chart type="line" :height="240"
                             :label="'Risk score for '.$name.' over the last ninety days'"
                             :data="[
                                 'labels' => $chart['labels'],
                                 'datasets' => [[
                                     'label' => 'Risk score',
                                     'data' => $chart['scores'],
                                     'borderColor' => \App\Support\Tone::chart($latest->band->tone()),
                                     'backgroundColor' => \App\Support\Tone::chart($latest->band->tone()).'22',
                                     'fill' => true,
                                     'tension' => 0.3,
                                 ]],
                             ]"
                             :options="['scales' => ['y' => ['min' => 0, 'max' => 100]]]" />
                @endif
            </x-card>
        </div>

        <x-card title="Assessment history" subtitle="Every run, newest first." class="mt-4">
            <x-data-table :headers="[
                'Calculated',
                ['label' => 'Score', 'align' => 'right'],
                'Band',
                'Heaviest factors',
            ]">
                @foreach ($history->sortByDesc('calculated_at') as $row)
                    <tr class="hover:bg-slate-50">
                        <td class="px-4 py-3 text-slate-600">{{ $row->calculated_at->format('j M Y, H:i') }}</td>
                        <td class="px-4 py-3 text-right tabular-nums font-medium text-slate-900">{{ round($row->score, 1) }}</td>
                        <td class="px-4 py-3"><x-status-badge :status="$row->band" /></td>
                        <td class="px-4 py-3 text-sm text-slate-600">
                            @forelse ($row->topFactors(2) as $factor => $points)
                                <span class="mr-2">{{ Str::headline($factor) }} <span class="tabular-nums text-slate-400">{{ round($points, 1) }}</span></span>
                            @empty
                                <span class="text-slate-400">Nothing measurable</span>
                            @endforelse
                        </td>
                    </tr>
                @endforeach
            </x-data-table>
        </x-card>
    @endif
</x-app-layout>
