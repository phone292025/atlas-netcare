@props([
    'label',
    'value',
    'unit' => null,
    'delta' => null,
    'trend' => null,
    'tone' => null,
    'href' => null,
])

{{--
    A single headline number, for the top of a dashboard.

    Usage:
        <x-stat-tile label="Access points online" value="37" unit="of 40" />

        <x-stat-tile label="Mean latency" value="142" unit="ms"
                     delta="+18 ms" trend="up" tone="urgent"
                     :href="route('engineer.aps.index')" />

    TONE IS THE CALLER'S DECISION, never inferred from the trend. Rising uptime
    is good and rising latency is bad; a component that guessed would get half
    the dashboard wrong. `trend` picks the arrow, `tone` picks the colour, and
    they are independent on purpose.

    Omit `tone` and the delta renders neutral — right for a number that moved
    without that being good or bad news.

    TABULAR NUMERALS: a column of tiles whose digits are different widths
    visibly jitters when the dashboard polls every 20 seconds. `tabular-nums`
    holds each digit to the same width so only the digits change, not the
    layout.

    This component never queries. It cannot tell whether the number came from a
    simulator or a live access point, and that is what lets the data source
    change without touching a dashboard.

    @see docs/08-ui-conventions.md — Blade components
--}}

@php
    $arrow = match ($trend) {
        'up' => 'chevron-up',
        'down' => 'chevron-down',
        default => null,
    };

    $deltaClass = \App\Support\Tone::accent($tone ?? \App\Support\Tone::NEUTRAL);

    $tag = filled($href) ? 'a' : 'div';
@endphp

<{{ $tag }}
    @if (filled($href)) href="{{ $href }}" @endif
    {{ $attributes->merge([
        'class' => 'block rounded-lg bg-white p-4 ring-1 ring-slate-200 shadow-sm'
            .(filled($href) ? ' hover:ring-slate-300 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500' : ''),
    ]) }}>

    <p class="text-xs font-medium uppercase tracking-wide text-slate-500">{{ $label }}</p>

    <div class="mt-1 flex items-baseline gap-1.5">
        <span class="text-2xl font-semibold text-slate-900 tabular-nums">{{ $value }}</span>

        @if (filled($unit))
            <span class="text-sm text-slate-500">{{ $unit }}</span>
        @endif
    </div>

    @if (filled($delta))
        <p class="mt-1 flex items-center gap-0.5 text-xs font-medium {{ $deltaClass }}">
            @if ($arrow)
                <x-icon :name="$arrow" class="w-3.5 h-3.5" />
            @endif
            <span class="tabular-nums">{{ $delta }}</span>
        </p>
    @endif

    {{-- A sparkline or any small inline visual belongs here. --}}
    @isset($sparkline)
        <div class="mt-3">{{ $sparkline }}</div>
    @endisset
</{{ $tag }}>
