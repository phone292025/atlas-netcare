@props(['tabs', 'label' => 'Views'])

{{--
    A row of views over one list, each a link with a count:

        <x-tab-nav :tabs="[
            ['label' => 'Open', 'url' => route(...), 'count' => 12, 'active' => true],
            ['label' => 'Past due', 'url' => route(...), 'count' => 3, 'tone' => 'urgent'],
        ]" />

    `tone` colours a non-zero count, for the view somebody should look at first.
    Scrolls sideways on a phone rather than wrapping.
--}}

<nav {{ $attributes->merge(['class' => '-mx-4 overflow-x-auto px-4 sm:mx-0 sm:px-0']) }} aria-label="{{ $label }}">
    <ul class="flex min-w-max gap-1 border-b border-line">
        @foreach ($tabs as $tab)
            @php
                $active = $tab['active'] ?? false;
                $count = $tab['count'] ?? null;
                $countClass = filled($tab['tone'] ?? null) && $count > 0
                    ? \App\Support\Tone::badge($tab['tone']).' ring-1 ring-inset'
                    : ($active ? 'bg-brand-50 text-brand-800' : 'bg-sunken text-fg-muted');
            @endphp
            <li>
                <a href="{{ $tab['url'] }}" @if ($active) aria-current="page" @endif
                   class="relative -mb-px flex h-10 items-center gap-2 border-b-2 px-3 text-sm transition
                          {{ $active ? 'border-brand-600 font-medium text-fg' : 'border-transparent text-fg-muted hover:text-fg' }}">
                    {{ $tab['label'] }}
                    @if ($count !== null)
                        <span class="inline-flex h-5 min-w-5 items-center justify-center rounded-md px-1.5 text-[11px] font-medium tabular-nums {{ $countClass }}">{{ $count }}</span>
                    @endif
                </a>
            </li>
        @endforeach
    </ul>
</nav>
