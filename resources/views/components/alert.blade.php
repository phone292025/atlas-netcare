@props(['type' => 'success', 'title' => null, 'dismissible' => false])

{{--
    A flash message. One per outcome, at the top of the content area.

    TWO VOCABULARIES, ON PURPOSE

    The prop is `type` — success / warning / error — because that is what a
    controller has to hand after an action, and what session keys are
    conventionally called. Tone is the internal colour vocabulary. This is the
    one place the two meet, and the translation is three lines rather than a
    concept anyone has to learn.

    An unrecognised type renders fuchsia through App\Support\Tone, the same way
    an unrecognised tone does, so a typo is visible on screen instead of quietly
    styling a failure message as a success.

    Colour comes from Tone::banner(). There is no second tone->class mapping in
    this file, and there must never be one — that is how 'urgent' ends up as two
    different reds.

    Usage:
        <x-alert type="error" title="Could not save">
            Asset tag APU-1042 is already in use.
        </x-alert>

    @see docs/08-ui-conventions.md — Design tokens, Interaction conventions
--}}

@php
    $tone = match ($type) {
        'success' => \App\Support\Tone::HEALTHY,
        'warning' => \App\Support\Tone::ATTENTION,
        'error' => \App\Support\Tone::URGENT,
        default => null,
    };

    $icon = match ($type) {
        'success' => 'check-circle',
        'warning' => 'warning',
        'error' => 'x-circle',
        default => 'help',
    };
@endphp

<div x-data="{ shown: true }"
     x-show="shown"
     role="alert"
     {{ $attributes->merge([
         'class' => 'flex items-start gap-3 rounded-md px-4 py-3 text-sm
                     ring-1 ring-inset '.\App\Support\Tone::banner($tone),
     ]) }}>

    <x-icon :name="$icon" class="w-5 h-5 flex-none mt-px {{ \App\Support\Tone::accent($tone) }}" />

    <div class="min-w-0 flex-1">
        @if (filled($title))
            <p class="font-semibold">{{ $title }}</p>
        @endif

        <div class="{{ filled($title) ? 'mt-0.5' : '' }}">{{ $slot }}</div>
    </div>

    @if ($dismissible)
        <button type="button"
                @click="shown = false"
                class="flex-none -mr-1 -mt-1 p-1 rounded opacity-60 hover:opacity-100
                       focus:outline-none focus-visible:ring-2 focus-visible:ring-current"
                aria-label="Dismiss message">
            <x-icon name="close" class="w-4 h-4" />
        </button>
    @endif
</div>
