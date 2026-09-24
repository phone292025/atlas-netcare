@props(['tone' => 'neutral', 'pill' => false, 'dot' => false])

{{--
    A small label coloured by tone. Enums return a tone, never a colour, and
    App\Support\Tone turns the tone into classes.

    Pages use <x-status-badge> or <x-priority-pill>, so the tone always comes
    from an enum rather than being picked on the page. `dot` adds a status dot
    in the tone's solid colour.
--}}

@php
    $classes = \App\Support\Tone::badge($tone);
    $shape = $pill ? 'rounded-full px-2.5' : 'rounded-md px-2';
@endphp

<span {{ $attributes->merge([
    'class' => "inline-flex h-6 items-center gap-1.5 text-xs font-medium ring-1 ring-inset whitespace-nowrap {$shape} {$classes}",
]) }}>
    @if ($dot)
        <span class="size-1.5 flex-none rounded-full {{ \App\Support\Tone::solid($tone) }}" aria-hidden="true"></span>
    @endif
    {{ $slot }}
</span>
