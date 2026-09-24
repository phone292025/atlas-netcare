@props(['size' => 'sm', 'wordmark' => true])

{{--
    The Atlas NetCare mark: a globe in the accent square, and the wordmark.
    One component for every layout, so no two screens show different branding.
--}}

@php
    [$box, $glyph, $text] = match ($size) {
        'lg' => ['size-11 rounded-xl', 'size-6', 'text-xl'],
        'md' => ['size-9 rounded-lg', 'size-5', 'text-lg'],
        default => ['size-7 rounded-md', 'size-4', 'text-[15px]'],
    };
@endphp

<span {{ $attributes->merge(['class' => 'inline-flex items-center gap-2.5 font-semibold tracking-tight text-fg']) }}>
    <span class="grid flex-none place-items-center bg-brand-600 text-white shadow-xs {{ $box }}" aria-hidden="true">
        <x-icon name="globe" :class="$glyph" />
    </span>

    @if ($wordmark)
        <span class="{{ $text }}">Atlas NetCare</span>
    @endif
</span>
