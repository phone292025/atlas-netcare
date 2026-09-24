@props(['variant' => 'primary', 'size' => null, 'href' => null, 'icon' => null, 'type' => 'button'])

{{--
    A button, or a link that looks like one when `href` is given.

        <x-button type="submit">Save changes</x-button>
        <x-button variant="secondary" :href="route('admin.users.index')">Cancel</x-button>
        <x-button variant="danger" icon="trash">Delete</x-button>

    Variants: primary, secondary, ghost, danger. Sizes: sm, lg.
    The styles live in resources/css/app.css as .btn-*.
--}}

@php
    $classes = trim("btn btn-{$variant} ".($size ? "btn-{$size}" : ''));
    $glyph = $size === 'sm' ? 'size-3.5' : 'size-4';
@endphp

@if (filled($href))
    <a href="{{ $href }}" {{ $attributes->merge(['class' => $classes]) }}>
        @if ($icon)<x-icon :name="$icon" :class="$glyph" />@endif
        {{ $slot }}
    </a>
@else
    <button type="{{ $type }}" {{ $attributes->merge(['class' => $classes]) }}>
        @if ($icon)<x-icon :name="$icon" :class="$glyph" />@endif
        {{ $slot }}
    </button>
@endif
