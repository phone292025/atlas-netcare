@props(['title' => null, 'subtitle' => null, 'flush' => false])

{{--
    A panel: a titled group of content on the page.

    `flush` drops the body padding, for a table or list that brings its own.
    A `header` slot sits on the right of the title, a `footer` slot below.

        <x-card title="Access point health" subtitle="Updated every 20 seconds">
            <x-slot:header><a href="..." class="link text-sm">View all</a></x-slot:header>
            ...
        </x-card>
--}}

<section {{ $attributes->merge(['class' => 'rounded-xl border border-line bg-surface shadow-xs']) }}>
    @if (filled($title) || filled($subtitle) || isset($header))
        <header class="flex items-start justify-between gap-4 border-b border-line px-5 py-4">
            <div class="min-w-0">
                @if (filled($title))
                    <h3 class="text-[15px] font-semibold text-fg">{{ $title }}</h3>
                @endif
                @if (filled($subtitle))
                    <p class="mt-0.5 text-sm text-fg-muted">{{ $subtitle }}</p>
                @endif
            </div>
            @isset($header)
                <div class="flex flex-none items-center gap-2">{{ $header }}</div>
            @endisset
        </header>
    @endif

    <div class="{{ $flush ? '' : 'p-5' }}">
        {{ $slot }}
    </div>

    @isset($footer)
        <footer class="border-t border-line px-5 py-3 text-sm">
            {{ $footer }}
        </footer>
    @endisset
</section>
