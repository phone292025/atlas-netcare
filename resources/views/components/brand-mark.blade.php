@props(['size' => 'sm', 'wordmark' => true])

{{--
    The Atlas NetCare mark: Atlas carrying the globe, and the wordmark.
    One component for every layout, so no two screens show different branding.

    The artwork is public/images/atlas-netcare-logo.png, dark ink on
    transparent. It is drawn as a mask over currentColor rather than as an
    <img>, so it takes the text colour and reads on both themes.
--}}

@php
    [$mark, $text] = match ($size) {
        'lg' => ['size-11', 'text-xl'],
        'md' => ['size-9', 'text-lg'],
        default => ['size-7', 'text-[15px]'],
    };

    $logo = "url('".asset('images/atlas-netcare-logo.png')."') center / contain no-repeat";
@endphp

<span {{ $attributes->merge(['class' => 'inline-flex items-center gap-2 font-semibold tracking-tight text-fg']) }}>
    <span @class(['flex-none', $mark])
          @if ($wordmark) aria-hidden="true" @else role="img" aria-label="Atlas NetCare" @endif
          style="background-color: currentColor; -webkit-mask: {{ $logo }}; mask: {{ $logo }};"></span>

    @if ($wordmark)
        <span class="{{ $text }}">Atlas NetCare</span>
    @endif
</span>
