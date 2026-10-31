<x-app-layout>
    <x-slot name="title">Maintenance</x-slot>

    {{-- FR-309. The screen that makes this a predictive maintenance system
         rather than a monitoring one: everything else in Atlas NetCare reports
         what has already happened, and this ranks what is about to.

         Lab PCs and access points are deliberately in ONE list. They are
         scored onto the same 0-100 scale so they can be compared, and a
         maintenance queue split across two tables that you had to merge by eye
         would not be a priority order at all.

         @see docs/decisions/ADR-0007-weighted-risk-not-ml.md --}}

    <x-page-header title="Maintenance priority"
                   :subtitle="($lastRun
                       ? 'Scored '.\Illuminate\Support\Carbon::parse($lastRun)->diffForHumans().'. '
                       : 'Never scored. ').'Machines and access points on one 0 to 100 scale, riskiest first.'">
        {{-- Reports are not in the engineer sidebar: every navigation entry
             must be namespaced under its own role, and this screen lives at
             admin.reports.index. Linked from here instead, which is arguably
             the better place anyway: mean time to resolution means most
             while you are looking at what is about to break. --}}
        <x-slot:actions>
            <x-button variant="secondary" icon="chart" :href="route('admin.reports.index')">Reports</x-button>
        </x-slot:actions>
    </x-page-header>

    @if (! $lastRun)
        <x-alert type="warning" class="mb-6" title="Nothing has been scored yet">
            Risk scores are written by <code class="kbd">php artisan atlas:recalculate-risk</code>,
            which runs daily at 02:00. Run it once to populate this screen.
        </x-alert>
    @endif

    {{-- Assets by risk band: the headline figure, and the fastest way to see
         that a weight change did something. Each one is also the filter. --}}
    <div class="mb-6 grid grid-cols-2 gap-3 lg:grid-cols-4">
        @foreach (\App\Enums\RiskBand::cases() as $case)
            @php($on = $band === $case)
            <a href="{{ route('engineer.risk.index', array_filter(['band' => $on ? null : $case->value, 'location' => $location])) }}"
               @if ($on) aria-current="true" @endif
               @class([
                   'group rounded-xl border bg-surface p-4 shadow-xs transition sm:p-5',
                   'border-brand-600 ring-1 ring-brand-600' => $on,
                   'border-line hover:border-line-strong hover:shadow-sm' => ! $on,
               ])>
                <p class="flex items-center justify-between gap-2 text-sm text-fg-muted">
                    <span class="flex items-center gap-2">
                        <span class="size-2 rounded-full {{ \App\Support\Tone::solid($case->tone()) }}"></span>
                        {{ $case->label() }}
                    </span>
                    @if ($on)
                        <x-icon name="close" class="size-3.5 text-fg-subtle" />
                    @endif
                </p>
                <p class="mt-3 text-[28px] font-semibold leading-none tracking-tight tabular-nums text-fg">{{ $spread[$case->value] ?? 0 }}</p>
            </a>
        @endforeach
    </div>

    <x-card flush>
        <x-slot:header>
            <form method="GET" action="{{ route('engineer.risk.index') }}" class="flex items-center gap-2">
                @if ($band)
                    <input type="hidden" name="band" value="{{ $band->value }}">
                @endif
                <select name="location" aria-label="Location" onchange="this.form.requestSubmit()" class="h-9 w-auto py-0 pl-3 pr-8 text-sm">
                    <option value="">Anywhere</option>
                    @foreach ($locations as $value => $label)
                        <option value="{{ $value }}" @selected((string) $location === (string) $value)>{{ $label }}</option>
                    @endforeach
                </select>
                <noscript><x-button type="submit" variant="secondary" size="sm">Filter</x-button></noscript>
                @if ($band || $location)
                    <x-button variant="ghost" size="sm" icon="close" :href="route('engineer.risk.index')">Clear</x-button>
                @endif
            </form>
        </x-slot:header>
        <x-slot:title>
            {{ $band ? $band->label() : 'Every asset' }}
        </x-slot:title>

        @if ($ranked->isEmpty())
            <x-empty-state title="No assets match these filters"
                           message="Clear the filters, or run php artisan atlas:recalculate-risk if nothing has been scored yet."
                           icon="wrench" />
        @else
            <div class="relative overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="border-b border-line bg-sunken/60 text-left text-xs font-medium text-fg-muted">
                        <tr>
                            <th scope="col" class="h-10 w-14 px-5 text-right font-medium">Rank</th>
                            <th scope="col" class="h-10 px-4 font-medium">Asset</th>
                            <th scope="col" class="h-10 px-4 font-medium">Location</th>
                            <th scope="col" class="h-10 px-4 font-medium">Risk</th>
                            <th scope="col" class="h-10 px-4 font-medium">Band</th>
                            <th scope="col" class="h-10 w-10 px-4"><span class="sr-only">Why</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        @foreach ($ranked as $i => $row)
                            @php($url = route('engineer.risk.show', [$row['type'], $row['id']]))
                            <tr class="group cursor-pointer transition-colors hover:bg-sunken/70" onclick="window.location='{{ $url }}'">
                                <td class="px-5 py-3 text-right tabular-nums text-fg-subtle">{{ $i + 1 }}</td>
                                <td class="px-4 py-3">
                                    <span class="flex items-center gap-3">
                                        <span class="grid size-8 flex-none place-items-center rounded-lg bg-sunken text-fg-muted">
                                            <x-icon :name="$row['type'] === 'access-point' ? 'wifi' : 'desktop'" class="size-4" />
                                        </span>
                                        <span class="min-w-0">
                                            <a href="{{ $url }}" class="block font-mono font-medium text-fg group-hover:text-brand-700">{{ $row['name'] }}</a>
                                            <span class="block truncate text-xs text-fg-muted">{{ $row['detail'] }}</span>
                                        </span>
                                    </span>
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-fg-muted">{{ $row['location'] ?? '-' }}</td>
                                <td class="px-4 py-3">
                                    @if ($row['score'] === null)
                                        {{-- Never scored is not low risk. Saying so keeps an
                                             unknown from reading as a clean bill of health. --}}
                                        <span class="text-xs text-fg-subtle">Not scored</span>
                                    @else
                                        <span class="flex w-36 items-center gap-2.5">
                                            <span class="w-7 text-right font-semibold tabular-nums text-fg">{{ round($row['score']) }}</span>
                                            <span class="h-1.5 flex-1 overflow-hidden rounded-full bg-sunken">
                                                <span class="block h-full rounded-full {{ \App\Support\Tone::solid($row['band']?->tone() ?? \App\Support\Tone::NEUTRAL) }}"
                                                      style="width: {{ (int) min(100, max(0, round($row['score']))) }}%"></span>
                                            </span>
                                        </span>
                                    @endif
                                </td>
                                <td class="px-4 py-3">
                                    @if ($row['band'])
                                        <x-status-badge :status="$row['band']" />
                                    @else
                                        <span class="text-xs text-fg-subtle">-</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <span class="inline-flex items-center gap-1 text-xs font-medium text-fg-muted group-hover:text-brand-700">
                                        Why <x-icon name="chevron-right" class="size-3.5 transition group-hover:translate-x-0.5" />
                                    </span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-card>
</x-app-layout>
