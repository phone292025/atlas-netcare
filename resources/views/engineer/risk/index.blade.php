<x-app-layout>
    <x-slot name="title">Maintenance priority</x-slot>

    {{-- FR-309. The screen that makes this a predictive maintenance system
         rather than a monitoring one: everything else in Atlas NetCare reports
         what has already happened, and this ranks what is about to.

         Lab PCs and access points are deliberately in ONE list. They are
         scored onto the same 0-100 scale so they can be compared, and a
         maintenance queue split across two tables that you had to merge by eye
         would not be a priority order at all.

         @see docs/decisions/ADR-0007-weighted-risk-not-ml.md --}}

    <x-page-header title="Maintenance priority"
                   :subtitle="$lastRun
                       ? 'Scored '.\Illuminate\Support\Carbon::parse($lastRun)->diffForHumans()
                       : 'Never scored'" />

    @if (! $lastRun)
        <x-alert type="warning" class="mb-4" title="Nothing has been scored yet">
            Risk scores are written by <code class="font-mono text-xs">php artisan atlas:recalculate-risk</code>,
            which runs daily at 02:00. Run it once to populate this screen.
        </x-alert>
    @endif

    {{-- Assets by risk band: the headline analytics figure, and the fastest
         way to see that a weight change did something. --}}
    <div class="mb-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
        @foreach (\App\Enums\RiskBand::cases() as $case)
            <x-stat-tile :label="$case->label()"
                         :value="$spread[$case->value] ?? 0"
                         :tone="$case->tone()"
                         :href="route('engineer.risk.index', ['band' => $case->value])" />
        @endforeach
    </div>

    <x-data-table :headers="[
        ['label' => 'Rank', 'align' => 'right'],
        'Asset',
        'Location',
        ['label' => 'Risk', 'align' => 'right'],
        'Band',
        '',
    ]">
        <x-slot:filters>
            <form method="GET" action="{{ route('engineer.risk.index') }}"
                  class="flex flex-wrap items-end gap-3">
                <x-form.select name="band" label="Band" :value="$band?->value"
                               blank="Any band"
                               :options="collect(\App\Enums\RiskBand::cases())
                                   ->mapWithKeys(fn ($c) => [$c->value => $c->label()])->all()" />

                <x-form.select name="location" label="Location" :value="$location"
                               blank="Anywhere" :options="$locations" />

                <button type="submit"
                        class="rounded-md bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700">
                    Filter
                </button>

                @if ($band || $location)
                    <a href="{{ route('engineer.risk.index') }}"
                       class="rounded-md px-3 py-2 text-sm font-medium text-slate-600 underline hover:text-slate-900">
                        Clear
                    </a>
                @endif
            </form>
        </x-slot:filters>

        @foreach ($ranked as $i => $row)
            <tr class="hover:bg-slate-50">
                <td class="px-4 py-3 text-right tabular-nums text-slate-500">{{ $i + 1 }}</td>

                <td class="px-4 py-3">
                    <div class="font-medium text-slate-900">{{ $row['name'] }}</div>
                    <div class="text-xs text-slate-500">{{ $row['detail'] }}</div>
                </td>

                <td class="px-4 py-3 text-slate-600">{{ $row['location'] ?? '—' }}</td>

                <td class="px-4 py-3 text-right">
                    @if ($row['score'] === null)
                        {{-- Never scored is not low risk. Saying so keeps an
                             unknown from reading as a clean bill of health. --}}
                        <span class="text-xs text-slate-400">Not scored</span>
                    @else
                        <span class="tabular-nums font-semibold text-slate-900">{{ round($row['score']) }}</span>
                    @endif
                </td>

                <td class="px-4 py-3">
                    @if ($row['band'])
                        <x-status-badge :status="$row['band']" />
                    @else
                        <span class="text-xs text-slate-400">—</span>
                    @endif
                </td>

                <td class="px-4 py-3 text-right">
                    <a href="{{ route('engineer.risk.show', [$row['type'], $row['id']]) }}"
                       class="text-sm font-medium text-brand-600 underline hover:text-brand-700">
                        Why?
                    </a>
                </td>
            </tr>
        @endforeach

        <x-slot:empty>
            <x-empty-state title="No assets match these filters"
                           message="Clear the filters, or run php artisan atlas:recalculate-risk if nothing has been scored yet."
                           icon="wrench" />
        </x-slot:empty>
    </x-data-table>
</x-app-layout>
