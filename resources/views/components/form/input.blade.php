@props([
    'name',
    'label' => null,
    'help' => null,
    'type' => 'text',
    'value' => null,
    'required' => false,
    'bag' => 'default',
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
    <input
        id="{{ $fieldId }}"
        name="{{ $name }}"
        type="{{ $type }}"
        value="{{ old($errorKey, $value) }}"
        @if ($required) required @endif
        @if ($hasError) aria-invalid="true" @endif
        @if ($describedBy !== '') aria-describedby="{{ $describedBy }}" @endif
        {{ $attributes->merge([
            'class' => 'block w-full h-10 text-sm disabled:cursor-not-allowed disabled:bg-sunken disabled:text-fg-subtle
                        '.($hasError ? 'border-red-500 focus:border-red-500 focus:ring-red-500/20' : ''),
        ]) }}
    >
</x-form.field>
