<x-app-layout>
    <x-slot name="title">Reports</x-slot>

    {{-- FR-410. Five figures, and they are deliberately not five views of the
         same table: inspection pass rates link M3 to outcomes, resolution
         times show M4's SLA is real, congestion links M5 to places, and the
         risk spread is M7's headline. Together they are the evidence that the
         modules connect rather than sitting side by side.

         Every one of these is meant to be screenshotted into the report.

         @see docs/modules/M7-predictive-and-reports.md --}}

    @php
        $scored = collect($riskSpread)->except('unscored')->sum('total');
        $atRisk = collect($riskSpread)->only(['high', 'critical'])->sum('total');
        $resolved = collect($resolution)->sum('resolved');
        $onTarget = collect($resolution)->filter(fn ($r) => $r['mean_hours'] !== null)->every(fn ($r) => $r['within_target']);
        $inspected = collect($inspections)->sum(fn ($r) => $r['passed'] + $r['failed']);
        $passed = collect($inspections)->sum('passed');
    @endphp

    <x-page-header title="Reports"
                   :subtitle="'How the campus is doing, from every module at once. Generated '.$generated->campus()->format('j M Y, H:i').'.'">
        <x-slot:actions>
            <x-button variant="secondary" icon="download" onclick="window.print()">Print or save as PDF</x-button>
        </x-slot:actions>
    </x-page-header>

    {{-- The four numbers someone asks for first. Each one is drawn from the
         section below it, so the detail is one scroll away. --}}
    <div class="mb-6 grid grid-cols-2 gap-3 lg:grid-cols-4">
        <x-stat-tile label="Assets at high risk or worse" icon="wrench" :value="$atRisk" :unit="'of '.$scored.' scored'"
                     :tone="$atRisk > 0 ? 'urgent' : null" :delta="$atRisk > 0 ? 'Worth a preventive visit' : null" />
        <x-stat-tile label="Tickets raised" icon="ticket" :value="$ticketVolume['total']" />
        <x-stat-tile label="Tickets resolved" icon="check-circle" :value="$resolved"
                     :tone="$resolved > 0 ? ($onTarget ? 'healthy' : 'attention') : null"
                     :delta="$resolved > 0 ? ($onTarget ? 'Every priority within target' : 'A priority is over target') : null" />
        <x-stat-tile label="Inspection pass rate" icon="list-checks"
                     :value="$inspected > 0 ? round($passed / $inspected * 100) : '-'" :unit="$inspected > 0 ? '% of '.$inspected : null" />
    </div>

    {{-- 1. Assets by risk band — the headline analytics figure. --}}
    <x-card title="Assets by risk band"
            subtitle="Predictive maintenance risk across every asset in service."
            class="mb-6">
        @if (! $riskChart['anyScored'])
            <x-empty-state title="Nothing has been scored yet"
                           message="Run php artisan atlas:recalculate-risk. It also runs daily at 02:00."
                           icon="wrench" />
        @else
            {{-- lg:items-center, because the chart is a fixed 240px and the
                 table beside it is as tall as it has rows. Stretched to the
                 row height the chart drew at the top and left a block of
                 empty card underneath it, which read as something failing to
                 load rather than as a short chart. --}}
            <div class="grid gap-6 lg:grid-cols-2 lg:items-center">
                <x-chart type="bar" :height="240"
                         label="Count of assets in each predictive risk band"
                         :data="[
                             'labels' => $riskChart['labels'],
                             'datasets' => [[
                                 'label' => 'Assets',
                                 'data' => $riskChart['data'],
                                 'backgroundColor' => $riskChart['colours'],
                             ]],
                         ]" />

                <x-data-table flat :headers="['Band', ['label' => 'Lab PCs', 'align' => 'right'], ['label' => 'Access points', 'align' => 'right'], ['label' => 'Total', 'align' => 'right']]">
                    @foreach ($riskSpread as $key => $row)
                        <tr @class(['hover:bg-sunken/70', 'text-fg-muted' => $key === 'unscored'])>
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
            class="mb-6">
        @if ($ticketVolume['total'] === 0)
            <x-empty-state title="No tickets yet" icon="ticket" />
        @else
            <x-data-table flat :headers="$ticketHeaders">
                @foreach ($ticketVolume['matrix'] as $row)
                    <tr class="hover:bg-sunken/70">
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
            class="mb-6">
        <x-data-table flat :headers="[
            'Priority',
            ['label' => 'Resolved', 'align' => 'right'],
            ['label' => 'Mean hours', 'align' => 'right'],
            ['label' => 'Target', 'align' => 'right'],
            'Against target',
        ]">
            @foreach ($resolution as $row)
                <tr class="hover:bg-sunken/70">
                    <td class="px-4 py-3">{{ $row['label'] }}</td>
                    <td class="px-4 py-3 text-right tabular-nums">{{ $row['resolved'] }}</td>
                    <td class="px-4 py-3 text-right tabular-nums">
                        {{ $row['mean_hours'] === null ? '-' : number_format($row['mean_hours'], 1) }}
                    </td>
                    <td class="px-4 py-3 text-right tabular-nums text-fg-muted">{{ $row['target_hours'] }}</td>
                    <td class="px-4 py-3">
                        @if ($row['mean_hours'] === null)
                            <span class="text-xs text-fg-subtle">
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

        <p class="mt-3 text-xs text-fg-muted">
            This is the aggregate picture. Individual breaches raise an alarm at the moment
            they happen. An average inside target can still hide one ticket that took a week.
        </p>
    </x-card>

    {{-- 4. Inspection pass rate by location. --}}
    <x-card title="Inspection pass rate by location"
            subtitle="Completed inspections only. An abandoned inspection has no outcome."
            class="mb-6">
        @if (empty($inspections))
            <x-empty-state title="No completed inspections yet" icon="clipboard" />
        @else
            {{-- lg:items-center, because the chart is a fixed 240px and the
                 table beside it is as tall as it has rows. Stretched to the
                 row height the chart drew at the top and left a block of
                 empty card underneath it, which read as something failing to
                 load rather than as a short chart. --}}
            <div class="grid gap-6 lg:grid-cols-2 lg:items-center">
                <x-chart type="bar" :height="240"
                         label="Percentage of inspections passed, by location"
                         :data="[
                             'labels' => $inspectionChart['labels'],
                             'datasets' => [[
                                 'label' => 'Pass rate %',
                                 'data' => $inspectionChart['data'],
                                 'backgroundColor' => $inspectionChart['colour'],
                             ]],
                         ]"
                         :options="['scales' => ['y' => ['min' => 0, 'max' => 100]]]" />

                <x-data-table flat :headers="['Location', ['label' => 'Passed', 'align' => 'right'], ['label' => 'Failed', 'align' => 'right'], ['label' => 'Rate', 'align' => 'right']]">
                    @foreach ($inspections as $row)
                        <tr class="hover:bg-sunken/70">
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
            <x-data-table flat :headers="[
                'Location',
                ['label' => 'APs', 'align' => 'right'],
                ['label' => 'Mean uptime', 'align' => 'right'],
                ['label' => 'Mean latency', 'align' => 'right'],
                ['label' => 'Peak clients', 'align' => 'right'],
            ]">
                @foreach ($congestion as $row)
                    <tr class="hover:bg-sunken/70">
                        <td class="px-4 py-3">{{ $row['location'] }}</td>
                        <td class="px-4 py-3 text-right tabular-nums">{{ $row['access_points'] }}</td>
                        <td class="px-4 py-3 text-right tabular-nums">{{ $row['uptime'] }}%</td>
                        <td class="px-4 py-3 text-right tabular-nums">{{ $row['latency'] }} ms</td>
                        <td class="px-4 py-3 text-right tabular-nums">{{ $row['peak_clients'] }}</td>
                    </tr>
                @endforeach
            </x-data-table>

            {{-- The column is every access point in the room at its busiest
                 hour, added together, not the busiest single one. Worth saying:
                 it read "Peak clients" and reported the busiest radio, which
                 came out as the same number for every location on this
                 campus. --}}
            <p class="mt-3 text-xs text-fg-muted">
                Peak clients adds up every access point in a room at its busiest hour, so it is
                an upper bound on how many people were online there at once, which is the
                direction to err in when deciding where to add capacity.
            </p>
        @endif
    </x-card>
</x-app-layout>
