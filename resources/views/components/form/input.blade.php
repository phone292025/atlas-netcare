@props([
    'name',
    'label' => null,
    'help' => null,
    'type' => 'text',
    'value' => null,
    'required' => false,
    'bag' => 'default',
    'revealable' => false,
])

{{--
    A single-line form control, wired to its label, help text and validation
    error without the caller doing anything.

    Usage:
        <x-form.input name="asset_tag" label="Asset tag" required
                      help="Printed on the sticker on the machine's case." />

        <x-form.input name="warranty_expiry" label="Warranty expires" type="date" />

    OLD INPUT: the value falls back to old($name) automatically. Without that, a
    failed validation returns a form with every field blanked and the user
    retypes work they already did — the single most common complaint in a UAT
    session. Pass `value` for the current model value; old input still wins.

    REVEALABLE: a password field with `revealable` gets an eye button that
    shows what was typed. On a phone keyboard a mistyped password is otherwise
    invisible until the sign-in fails.

    @see docs/06-ui-conventions.md — Blade components
--}}

@php
    $fieldId = \App\Support\FormField::id($name);
    $errorKey = \App\Support\FormField::errorKey($name);
    $hasError = $errors->getBag($bag)->has($errorKey);

    $describedBy = implode(' ', array_filter([
        filled($help) ? $fieldId.'-help' : null,
        $hasError ? $fieldId.'-error' : null,
    ]));
@endphp

<x-form.field :name="$name" :label="$label" :help="$help" :required="$required" :bag="$bag">
    @if ($revealable)
        <div class="relative" x-data="{ shown: false }">
    @endif
    <input
        id="{{ $fieldId }}"
        name="{{ $name }}"
        type="{{ $type }}"
        @if ($revealable) :type="shown ? 'text' : '{{ $type }}'" @endif
        value="{{ old($errorKey, $value) }}"
        @if ($required) required @endif
        @if ($hasError) aria-invalid="true" @endif
        @if ($describedBy !== '') aria-describedby="{{ $describedBy }}" @endif
        {{ $attributes->merge([
            'class' => 'block w-full h-10 text-sm disabled:cursor-not-allowed disabled:bg-sunken disabled:text-fg-subtle
                        '.($revealable ? 'pr-11 ' : '').($hasError ? 'border-red-500 focus:border-red-500 focus:ring-red-500/20' : ''),
        ]) }}
    >
    @if ($revealable)
            <button type="button" @click="shown = ! shown"
                    class="absolute inset-y-0 right-0 grid w-11 place-items-center rounded-r-md text-fg-subtle transition-colors hover:text-fg"
                    :aria-label="shown ? 'Hide password' : 'Show password'" aria-label="Show password"
                    :aria-pressed="shown.toString()" aria-controls="{{ $fieldId }}">
                <x-icon name="eye" class="size-[18px]" x-show="! shown" />
                <x-icon name="eye-slash" class="size-[18px]" x-show="shown" x-cloak />
            </button>
        </div>
    @endif
</x-form.field>
