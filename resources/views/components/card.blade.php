@props(['title' => null, 'subtitle' => null, 'flush' => false])

{{--
    The standard content container. Everything that is not a bare table sits in
    one of these, so pages across seven module owners share one surface.

    Padding is p-6 and stays p-6. If a card somewhere needs different padding,
    the answer is almost always that it should not be a card.

    `flush` removes the body padding, for content that manages its own edges —
    a <x-data-table> is the usual case, because a table's own cell padding
    already provides the inset and doubling it looks like a mistake.

    Usage:
        <x-card title="Access point health">
            ...
            <x-slot:footer>
                <a href="...">View all</a>
            </x-slot:footer>
        </x-card>

    @see docs/06-ui-conventions.md — Blade components
--}}

<div {{ $attributes->merge(['class' => 'bg-white rounded-lg ring-1 ring-slate-200 shadow-sm']) }}>
    @if (filled($title) || filled($subtitle) || isset($header))
        <div class="flex items-start justify-between gap-4 px-6 py-4 border-b border-slate-200">
            <div class="min-w-0">
                @if (filled($title))
                    <h3 class="text-sm font-semibold text-slate-900">{{ $title }}</h3>
                @endif

                @if (filled($subtitle))
                    <p class="mt-0.5 text-sm text-slate-500">{{ $subtitle }}</p>
                @endif
            </div>

            @isset($header)
                <div class="flex-none flex items-center gap-2">{{ $header }}</div>
            @endisset
        </div>
    @endif

    <div class="{{ $flush ? '' : 'p-6' }}">
        {{ $slot }}
    </div>

    @isset($footer)
        <div class="px-6 py-3 border-t border-slate-200 bg-slate-50/60 rounded-b-lg text-sm">
            {{ $footer }}
        </div>
    @endisset
</div>
