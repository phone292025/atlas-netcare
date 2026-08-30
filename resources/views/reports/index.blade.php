<x-app-layout>
    <x-slot name="title">Reports</x-slot>

    {{-- FR-410. Five figures, and they are deliberately not five views of the
         same table: inspection pass rates link M3 to outcomes, resolution
         times show M4's SLA is real, congestion links M5 to places, and the
         risk spread is M7's headline. Together they are the evidence that the
         modules connect rather than sitting side by side.

         Every one of these is meant to be screenshotted into the report.

         @see docs/modules/M7-predictive-and-reports.md --}}

    <x-page-header title="Reports"
                   :subtitle="'Generated '.$generated->format('j M Y, H:i')" />

    {{-- 1. Assets by risk band — the headline analytics figure. --}}
    <x-card title="Assets by risk band"
            subtitle="Predictive maintenance risk across every asset in service."
            class="mb-4">
        @php
            $bandLabels = collect($riskSpread)->except('unscored')->pluck('label')->values()->all();
            $bandTotals = collect($riskSpread)->except('unscored')->pluck('total')->values()->all();
            $anyScored = array_sum($bandTotals) > 0;
        @endphp

        @if (! $anyScored)
            <x-empty-state title="Nothing has been scored yet"
                           message="Run php artisan atlas:recalculate-risk. It also runs daily at 02:00."
                           icon="wrench" />
        @else
            <div class="grid gap-6 lg:grid-cols-2">
                <x-chart type="bar" :height="240"
                         label="Count of assets in each predictive risk band"
                         :data="[
                             'labels' => $bandLabels,
                             'datasets' => [[
                                 'label' => 'Assets',
                                 'data' => $bandTotals,
                                 'backgroundColor' => collect(\App\Enums\RiskBand::cases())
                                     ->map(fn ($b) => \App\Support\Tone::chart($b->tone()))->values()->all(),
                             ]],
                         ]" />

                <x-data-table :headers="['Band', ['label' => 'Lab PCs', 'align' => 'right'], ['label' => 'Access points', 'align' => 'right'], ['label' => 'Total', 'align' => 'right']]">
                    @foreach ($riskSpread as $key => $row)
                        <tr @class(['hover:bg-slate-50', 'text-slate-500' => $key === 'unscored'])>
                            <td class="px-4 py-3">{{ $row['label'] }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ $row['lab_pcs'] }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ $row['access_points'] }}</td>
                            <td class="px-4 py-3 text-right tabular-nums font-medium">{{ $row['total'] }}</td>
                        </tr>
                    @endforeach
                </x-data-table>
            </div>
        @endif
    </x-card>

    {{-- 2. Ticket volume by category and priority. --}}
    <x-card title="Ticket volume"
            :subtitle="$ticketVolume['total'].' tickets by category and priority.'"
            class="mb-4">
        @if ($ticketVolume['total'] === 0)
            <x-empty-state title="No tickets yet" icon="ticket" />
        @else
            <x-data-table :headers="array_merge(
                ['Category'],
                collect($ticketVolume['priorities'])->map(fn ($p) => ['label' => $p->label(), 'align' => 'right'])->all(),
                [['label' => 'Total', 'align' => 'right']],
            )">
                @foreach ($ticketVolume['matrix'] as $row)
                    <tr class="hover:bg-slate-50">
                        <td class="px-4 py-3">{{ $row['label'] }}</td>
                        @foreach ($ticketVolume['priorities'] as $priority)
                            <td class="px-4 py-3 text-right tabular-nums">{{ $row[$priority->value] }}</td>
                        @endforeach
                        <td class="px-4 py-3 text-right tabular-nums font-medium">{{ $row['total'] }}</td>
                    </tr>
                @endforeach
            </x-data-table>
        @endif
    </x-card>

    {{-- 3. Mean time to resolution, against the SLA target. --}}
    <x-card title="Mean time to resolution"
            subtitle="Measured from when a ticket was opened, the same baseline the SLA uses."
            class="mb-4">
        <x-data-table :headers="[
            'Priority',
            ['label' => 'Resolved', 'align' => 'right'],
            ['label' => 'Mean hours', 'align' => 'right'],
            ['label' => 'Target', 'align' => 'right'],
            'Against target',
        ]">
            @foreach ($resolution as $row)
                <tr class="hover:bg-slate-50">
                    <td class="px-4 py-3">{{ $row['label'] }}</td>
                    <td class="px-4 py-3 text-right tabular-nums">{{ $row['resolved'] }}</td>
                    <td class="px-4 py-3 text-right tabular-nums">
                        {{ $row['mean_hours'] === null ? '—' : number_format($row['mean_hours'], 1) }}
                    </td>
                    <td class="px-4 py-3 text-right tabular-nums text-slate-500">{{ $row['target_hours'] }}</td>
                    <td class="px-4 py-3">
                        @if ($row['mean_hours'] === null)
                            <span class="text-xs text-slate-400">
                                {{ $row['resolved'] === 0 ? 'Nothing resolved yet' : 'No usable timestamps' }}
                            </span>
                        @else
                            <x-badge :tone="$row['within_target'] ? 'healthy' : 'urgent'">
                                {{ $row['within_target'] ? 'Within target' : 'Over target' }}
                            </x-badge>
                        @endif

                        @if ($row['excluded'] > 0)
                            {{-- Said out loud rather than quietly dropped. These
                                 rows were written before tickets.opened_at
                                 stopped rewriting itself on 2026-08-29, so they
                                 claim to have been resolved before they were
                                 opened. --}}
                            <p class="mt-1 text-xs text-amber-700">
                                {{ $row['excluded'] }} excluded, resolved before opened
                            </p>
                        @endif
                    </td>
                </tr>
            @endforeach
        </x-data-table>

        <p class="mt-3 text-xs text-slate-500">
            This is the aggregate picture. Individual breaches raise an alarm at the moment
            they happen — an average inside target can still hide one ticket that took a week.
        </p>
    </x-card>

    {{-- 4. Inspection pass rate by location. --}}
    <x-card title="Inspection pass rate by location"
            subtitle="Completed inspections only. An abandoned inspection has no outcome."
            class="mb-4">
        @if (empty($inspections))
            <x-empty-state title="No completed inspections yet" icon="clipboard" />
        @else
            <div class="grid gap-6 lg:grid-cols-2">
                <x-chart type="bar" :height="240"
                         label="Percentage of inspections passed, by location"
                         :data="[
                             'labels' => collect($inspections)->pluck('location')->all(),
                             'datasets' => [[
                                 'label' => 'Pass rate %',
                                 'data' => collect($inspections)->pluck('rate')->all(),
                                 'backgroundColor' => \App\Support\Tone::chart('healthy'),
                             ]],
                         ]"
                         :options="['scales' => ['y' => ['min' => 0, 'max' => 100]]]" />

                <x-data-table :headers="['Location', ['label' => 'Passed', 'align' => 'right'], ['label' => 'Failed', 'align' => 'right'], ['label' => 'Rate', 'align' => 'right']]">
                    @foreach ($inspections as $row)
                        <tr class="hover:bg-slate-50">
                            <td class="px-4 py-3">{{ $row['location'] }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ $row['passed'] }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ $row['failed'] }}</td>
                            <td class="px-4 py-3 text-right tabular-nums font-medium">{{ $row['rate'] }}%</td>
                        </tr>
                    @endforeach
                </x-data-table>
            </div>
        @endif
    </x-card>

    {{-- 5. AP uptime and peak congestion by location. --}}
    <x-card title="Access point uptime and congestion"
            subtitle="Last thirty days, by location.">
        @if (empty($congestion))
            <x-empty-state title="No telemetry in the last thirty days"
                           message="Run php artisan atlas:backfill-telemetry --days=14 to populate this."
                           icon="wifi" />
        @else
            <x-data-table :headers="[
                'Location',
                ['label' => 'APs', 'align' => 'right'],
                ['label' => 'Mean uptime', 'align' => 'right'],
                ['label' => 'Mean latency', 'align' => 'right'],
                ['label' => 'Peak clients', 'align' => 'right'],
            ]">
                @foreach ($congestion as $row)
                    <tr class="hover:bg-slate-50">
                        <td class="px-4 py-3">{{ $row['location'] }}</td>
                        <td class="px-4 py-3 text-right tabular-nums">{{ $row['access_points'] }}</td>
                        <td class="px-4 py-3 text-right tabular-nums">{{ $row['uptime'] }}%</td>
                        <td class="px-4 py-3 text-right tabular-nums">{{ $row['latency'] }} ms</td>
                        <td class="px-4 py-3 text-right tabular-nums">{{ $row['peak_clients'] }}</td>
                    </tr>
                @endforeach
            </x-data-table>
        @endif
    </x-card>
</x-app-layout>
