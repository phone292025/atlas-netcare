@props(['size' => 'sm', 'wordmark' => true])

{{--
    The Atlas NetCare mark.

    One component, used by both layouts, so the login screen and the signed-in
    shell cannot show different branding. It replaces Breeze's
    <x-application-logo>, which was the Laravel logo — the first thing anyone
    opening the system saw was the framework's branding rather than the project.

    The mark is deliberately a letterform rather than an SVG illustration: no
    asset to lose, no file to license, and it scales cleanly.
--}}

@php
    $box = match ($size) {
        'lg' => 'w-12 h-12 rounded-xl text-lg',
        'md' => 'w-10 h-10 rounded-lg text-base',
        default => 'w-8 h-8 rounded-lg text-sm',
    };

    $text = match ($size) {
        'lg' => 'text-xl',
        'md' => 'text-lg',
        default => 'text-base',
    };
@endphp

<span {{ $attributes->merge(['class' => 'inline-flex items-center gap-2 font-semibold text-slate-900']) }}>
    <span class="grid flex-none place-items-center bg-brand-600 font-bold text-white {{ $box }}"
          aria-hidden="true">A</span>

    @if ($wordmark)
        <span class="{{ $text }}">Atlas NetCare</span>
    @endif
</span>
