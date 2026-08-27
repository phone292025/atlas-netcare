@props(['tone' => 'neutral', 'pill' => false])

{{--
    THE single place that decides what a tone looks like.

    Enums return a tone — 'healthy', 'attention', 'urgent', 'neutral' — and
    never a colour. This file turns a tone into classes. Change a colour here
    and it changes in every badge, pill, table and gauge at once.

    The classes are written as complete literal strings on purpose. Tailwind
    scans source files for class names, so a class assembled at runtime
    (`bg-{$colour}-50`) would be purged from the build and render unstyled.

    Do not use this component directly in a page. Use <x-status-badge> or
    <x-priority-pill>, so the tone always comes from an enum rather than a
    hand-typed string.

    @see docs/08-ui-conventions.md — Design tokens
--}}

@php
    $classes = match ($tone) {
        'healthy' => 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
        'attention' => 'bg-amber-50 text-amber-800 ring-amber-600/20',
        'urgent' => 'bg-red-50 text-red-700 ring-red-600/20',
        'neutral' => 'bg-slate-50 text-slate-600 ring-slate-500/20',
        // An unknown tone is a bug, not a styling choice. Make it visible.
        default => 'bg-fuchsia-100 text-fuchsia-900 ring-fuchsia-600/40',
    };

    $shape = $pill ? 'rounded-full px-2.5' : 'rounded-md px-2';
@endphp

<span {{ $attributes->merge([
    'class' => "inline-flex items-center gap-1 py-0.5 text-xs font-medium
                ring-1 ring-inset whitespace-nowrap {$shape} {$classes}",
]) }}>
    {{ $slot }}
</span>
