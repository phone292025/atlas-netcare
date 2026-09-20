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

    @see docs/06-ui-conventions.md
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
        'check-circle' => '<circle cx="12" cy="12" r="9"/><path d="M8.5 12.5l2.5 2.5 4.5-5"/>',
        'warning' => '<path d="M10.3 4.3 2.8 17.2A2 2 0 0 0 4.5 20h15a2 2 0 0 0 1.7-2.8L13.7 4.3a2 2 0 0 0-3.4 0z"/><path d="M12 9.5v4M12 17v.01"/>',
        'x-circle' => '<circle cx="12" cy="12" r="9"/><path d="M9 9l6 6M15 9l-6 6"/>',
        'inbox' => '<path d="M3 13h4l1.5 3h7L17 13h4"/><path d="M5.5 5h13l2.5 8v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4l2.5-8z"/>',
        'chevron-left' => '<path d="M15 6l-6 6 6 6"/>',
        'chevron-right' => '<path d="M9 6l6 6-6 6"/>',
        'chevron-up' => '<path d="M6 15l6-6 6 6"/>',
        'sort' => '<path d="M8 9l-3-3-3 3M5 6v12"/><path d="M16 15l3 3 3-3M19 18V6"/>',
        'plus' => '<path d="M12 5v14M5 12h14"/>',
        // The Network monitor: its sidebar entry, its controls, and the
        // scenario badge on an access point the simulator is driving.
        'broadcast' => '<circle cx="12" cy="12" r="1.5"/><path d="M8.6 8.6a4.8 4.8 0 0 0 0 6.8M15.4 8.6a4.8 4.8 0 0 1 0 6.8"/><path d="M5.6 5.6a9 9 0 0 0 0 12.8M18.4 5.6a9 9 0 0 1 0 12.8"/>',
        'flask' => '<path d="M9 3h6M10 3v6.5L4.6 18.4A1.7 1.7 0 0 0 6.1 21h11.8a1.7 1.7 0 0 0 1.5-2.6L14 9.5V3"/><path d="M7.2 15h9.6"/>',
        'refresh' => '<path d="M20 11a8 8 0 0 0-14.6-4.5L4 8"/><path d="M4 4v4h4"/><path d="M4 13a8 8 0 0 0 14.6 4.5L20 16"/><path d="M20 20v-4h-4"/>',
        'search' => '<circle cx="11" cy="11" r="6.5"/><path d="M20 20l-4.3-4.3"/>',
        'arrow-up-right' => '<path d="M7 17L17 7M8.5 7H17v8.5"/>',
        default => '<circle cx="12" cy="12" r="9"/><path d="M12 16v.01M12 13c0-2 2-2 2-3.5A2 2 0 0 0 10 9"/>',
    };
@endphp

<svg {{ $attributes->merge(['class' => $class]) }}
     viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"
     stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
    {!! $paths !!}
</svg>
