@props(['name', 'class' => 'w-5 h-5'])

{{--
    Inline SVG icons.

    Hand-built rather than pulled from an icon package: eleven navigation icons
    and a handful of interface ones do not justify a dependency, and inlining
    means no extra HTTP request and no build step.

    All icons share one grid — 24x24 viewBox, stroke-based, 1.5 stroke width,
    round caps and joins — so they sit together consistently at any size.

    An unknown name renders a visible "?" rather than nothing, so a typo shows
    up on screen instead of leaving a silent gap in the sidebar.

    @see docs/08-ui-conventions.md
--}}

@php
    $paths = match ($name) {
        'calendar' => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M8 3v4M16 3v4M3 10h18"/>',
        'clipboard' => '<path d="M8 5H6a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/><rect x="8" y="3" width="8" height="4" rx="1"/>',
        'ticket' => '<path d="M4 8a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v1a2 2 0 0 0 0 4v1a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2v-1a2 2 0 0 0 0-4V8z"/><path d="M12 9v1M12 12.5v1M12 16v1"/>',
        'wifi' => '<path d="M3 9Q12 2 21 9"/><path d="M6.5 12.5Q12 8 17.5 12.5"/><path d="M9.5 16Q12 14 14.5 16"/><circle cx="12" cy="19" r="1"/>',
        'gauge' => '<path d="M4 17Q12 5 20 17"/><path d="M12 17l3.5-4.5"/>',
        'chart' => '<path d="M3 20h18"/><path d="M6.5 20v-6M11.5 20v-11M16.5 20v-8"/>',
        'wrench' => '<circle cx="16" cy="8" r="4"/><path d="M13.2 10.8 4 20"/>',
        'users' => '<circle cx="9" cy="8" r="3.5"/><path d="M3 20a6 6 0 0 1 12 0"/><path d="M16 5.7a3.5 3.5 0 0 1 0 4.6"/><path d="M17 14.6a6 6 0 0 1 4 5.4"/>',
        'building' => '<rect x="4" y="3" width="16" height="18" rx="1"/><path d="M8 7h2M14 7h2M8 11h2M14 11h2"/><path d="M10 21v-3h4v3"/>',
        'desktop' => '<rect x="3" y="4" width="18" height="12" rx="2"/><path d="M9 20h6M12 16v4"/>',
        'shield' => '<path d="M12 3l8 3v6c0 4.5-3.2 8.4-8 9.5C7.2 20.4 4 16.5 4 12V6l8-3z"/>',
        'bell' => '<path d="M18 15v-5a6 6 0 1 0-12 0v5l-1.5 2.5h15L18 15z"/><path d="M10 20a2 2 0 0 0 4 0"/>',
        'menu' => '<path d="M4 7h16M4 12h16M4 17h16"/>',
        'close' => '<path d="M6 6l12 12M18 6L6 18"/>',
        'chevron-down' => '<path d="M6 9l6 6 6-6"/>',
        'logout' => '<path d="M15 4h3a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2h-3"/><path d="M10 8l-4 4 4 4M6 12h10"/>',
        'user' => '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
        default => '<circle cx="12" cy="12" r="9"/><path d="M12 16v.01M12 13c0-2 2-2 2-3.5A2 2 0 0 0 10 9"/>',
    };
@endphp

<svg {{ $attributes->merge(['class' => $class]) }}
     viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"
     stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
    {!! $paths !!}
</svg>
