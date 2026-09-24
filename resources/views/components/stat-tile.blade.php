@props([
    'label',
    'value',
    'unit' => null,
    'delta' => null,
    'trend' => null,
    'tone' => null,
    'href' => null,
    'icon' => null,
])

{{--
    One headline number, for the top of a dashboard.

        <x-stat-tile label="Access points online" value="37" unit="of 40" icon="wifi" />

        <x-stat-tile label="Mean latency" value="142" unit="ms"
                     delta="+18 ms" trend="up" tone="urgent"
                     :href="route('engineer.aps.index')" />

    Tone is the caller's decision, never inferred from the trend. Rising uptime
    is good and rising latency is bad; `trend` picks the arrow, `tone` the
    colour, and they are independent on purpose. Numbers are tabular, so a
    value that changes on a poll does not jitter.
--}}

@php
    $arrow = match ($trend) {
        'up' => 'trend-up',
        'down' => 'trend-down',
        default => null,
    };
    $deltaClass = \App\Support\Tone::accent($tone ?? \App\Support\Tone::NEUTRAL);
    $tag = filled($href) ? 'a' : 'div';
@endphp

<{{ $tag }}
    @if (filled($href)) href="{{ $href }}" @endif
    {{ $attributes->merge([
        'class' => 'group flex flex-col rounded-xl border border-line bg-surface p-4 shadow-xs sm:p-5'
            .(filled($href) ? ' transition hover:border-line-strong hover:shadow-sm' : ''),
    ]) }}>
    <div class="flex items-center justify-between gap-2">
        <p class="truncate text-sm text-fg-muted">{{ $label }}</p>
        @if (filled($icon))
            <x-icon :name="$icon" class="size-4 flex-none text-fg-subtle" />
        @elseif (filled($href))
            <x-icon name="arrow-up-right" class="size-4 flex-none text-fg-subtle opacity-0 transition group-hover:opacity-100" />
        @endif
    </div>

    <div class="mt-3 flex items-baseline gap-1.5">
        <span class="text-[28px] font-semibold leading-none tracking-tight text-fg tabular-nums">{{ $value }}</span>
        @if (filled($unit))
            <span class="text-sm text-fg-muted">{{ $unit }}</span>
        @endif
    </div>

    @if (filled($delta))
        <p class="mt-2 flex items-center gap-1 text-xs font-medium {{ $deltaClass }}">
            @if ($arrow)
                <x-icon :name="$arrow" class="size-3.5" />
            @endif
            <span class="tabular-nums">{{ $delta }}</span>
        </p>
    @endif

    @isset($sparkline)
        <div class="mt-4">{{ $sparkline }}</div>
    @endisset
</{{ $tag }}>
