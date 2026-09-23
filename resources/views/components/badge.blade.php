@props(['tone' => 'neutral', 'pill' => false])

{{--
    A small inline chip carrying a semantic tone.

    Enums return a tone, never a colour. App\Support\Tone turns that tone
    into classes, and it is the only place in the system that does.

    Do not use this component directly in a page. Use <x-status-badge> or
    <x-priority-pill>, so the tone always comes from an enum rather than a
    hand-typed string.

    @see docs/06-ui-conventions.md — Design tokens
--}}

@php
    $classes = \App\Support\Tone::badge($tone);
    $shape = $pill ? 'rounded-full px-2.5' : 'rounded-md px-2';
@endphp

<span {{ $attributes->merge([
    'class' => "inline-flex items-center gap-1 py-0.5 text-xs font-medium
                ring-1 ring-inset whitespace-nowrap {$shape} {$classes}",
]) }}>
    {{ $slot }}
</span>
