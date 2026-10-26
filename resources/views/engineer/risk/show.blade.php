@php
    $isPc = $type === 'lab-pc';
    $name = $isPc ? $asset->asset_tag : $asset->code;
    $first = $history->first();
    $change = $latest && $first && $history->count() > 1 ? round($latest->score - $first->score) : null;
@endphp

<x-app-layout>
    <x-slot name="title">{{ $name }} risk</x-slot>

    {{-- FR-310. The screen that turns a score into analytics.

         A bare number could have come from a threshold rule. The breakdown
         below says WHY, and the chart says whether it is getting worse, which
         together are the difference between monitoring and prediction, and the
         reason ADR-0007 chose a weighted model over a trained one.

         @see docs/decisions/ADR-0007-weighted-risk-not-ml.md --}}

    <a href="{{ route('engineer.risk.index') }}"
       class="mb-4 inline-flex items-center gap-1.5 text-sm text-fg-muted hover:text-fg">
        <x-icon name="arrow-left" class="size-4" /> Maintenance priority
    </a>

    <x-page-header :title="$name"
                   :subtitle="($isPc ? $asset->hostname : $asset->model).' · '.($asset->location?->label() ?? 'No location')">
        <x-slot:actions>
            {{-- FR-311. Preventive, not reactive: every other ticket in the
                 system is raised because something broke. --}}
            <x-button icon="wrench" :href="route('assistant.tickets.create', ['asset' => $type, 'asset_id' => $asset->id])">
                Raise preventive ticket
            </x-button>
        </x-slot:actions>
    </x-page-header>

    @if (! $latest)
        <x-card>
            <x-empty-state title="This asset has never been scored"
                           message="Risk scores are written by php artisan atlas:recalculate-risk, which runs daily at 02:00."
                           icon="wrench" />
        </x-card>
    @else
        <div class="grid gap-6 lg:grid-cols-[22rem_minmax(0,1fr)]">
            <x-card title="Current risk" :subtitle="'Calculated '.$latest->calculated_at->diffForHumans()" class="self-start">
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

                    <p class="mt-5 border-t border-line pt-4 text-xs leading-relaxed text-fg-muted">
                        Each factor is a measurement divided by a ceiling, clamped to its
                        share, and weighted. The parts add up to the total.
                    </p>
                @endif
            </x-card>

            <x-card title="Risk over time" subtitle="Ninety days. A score climbing is the prediction.">
                @if ($change !== null)
                    <x-slot:header>
                        <span @class([
                            'inline-flex items-center gap-1 rounded-md px-2 py-1 text-xs font-medium tabular-nums',
                            'bg-red-50 text-red-700' => $change > 0,
                            'bg-emerald-50 text-emerald-700' => $change < 0,
                            'bg-sunken text-fg-muted' => $change == 0,
                        ])>
                            @if ($change != 0)
                                <x-icon :name="$change > 0 ? 'trend-up' : 'trend-down'" class="size-3.5" />
                            @endif
                            {{ $change == 0 ? 'No change' : ($change > 0 ? '+' : '').$change }} since {{ $first->calculated_at->campus()->format('j M') }}
                        </span>
                    </x-slot:header>
                @endif

                @if ($history->count() < 2)
                    <x-empty-state title="Not enough history to draw a trend"
                                   message="A trend needs at least two runs. The daily job builds this up on its own."
                                   icon="chart" />
                @else
                    <x-chart type="line" :height="260"
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

        <x-card title="Assessment history" subtitle="Every run, newest first." class="mt-6" flush>
            <div class="max-h-[28rem] overflow-auto">
                <table class="min-w-full text-sm">
                    <thead class="sticky top-0 border-b border-line bg-sunken text-left text-xs font-medium text-fg-muted">
                        <tr>
                            <th scope="col" class="h-10 px-5 font-medium">Calculated</th>
                            <th scope="col" class="h-10 px-4 text-right font-medium">Score</th>
                            <th scope="col" class="h-10 px-4 font-medium">Band</th>
                            <th scope="col" class="h-10 px-5 font-medium">Heaviest factors</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        @foreach ($history->sortByDesc('calculated_at') as $row)
                            <tr class="hover:bg-sunken/60">
                                <td class="whitespace-nowrap px-5 py-3 text-fg-muted">{{ $row->calculated_at->campus()->format('j M Y, H:i') }}</td>
                                <td class="px-4 py-3 text-right font-medium tabular-nums text-fg">{{ round($row->score, 1) }}</td>
                                <td class="px-4 py-3"><x-status-badge :status="$row->band" /></td>
                                <td class="px-5 py-3">
                                    <span class="flex flex-wrap gap-1.5">
                                        @forelse ($row->topFactors(2) as $factor => $points)
                                            <span class="inline-flex items-center gap-1.5 rounded-md bg-sunken px-2 py-0.5 text-xs text-fg-muted">
                                                {{ \App\Models\RiskAssessment::factorLabel($factor) }}
                                                <span class="tabular-nums text-fg">{{ round($points, 1) }}</span>
                                            </span>
                                        @empty
                                            <span class="text-xs text-fg-subtle">Nothing measurable</span>
                                        @endforelse
                                    </span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-card>
    @endif
</x-app-layout>
