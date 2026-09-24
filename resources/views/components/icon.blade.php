@props(['name', 'class' => 'size-5', 'fill' => false])

{{--
    A Phosphor icon, by the app's own name: <x-icon name="ticket" class="size-4" />

    `fill` swaps to the filled weight, used for the active navigation item.
    The names and the glyphs behind them are in scripts/build-icons.mjs.
--}}

<svg {{ $attributes->merge(['class' => $class]) }}
     xmlns="http://www.w3.org/2000/svg" viewBox="0 0 256 256" fill="currentColor"
     aria-hidden="true" focusable="false">{!! \App\Support\Icons::markup($name, (bool) $fill) !!}</svg>
