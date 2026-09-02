@props([
    'name',
    'label' => null,
    'help' => null,
    'rows' => 4,
    'value' => null,
    'required' => false,
    'bag' => 'default',
])

{{--
    A multi-line form control, wired to its label, help text and validation
    error.

    Used for handover notes, ticket descriptions, resolution notes and
    checklist remarks.

    Usage:
        <x-form.textarea name="resolution_notes" label="How was it resolved?"
                         required rows="5"
                         help="What you did, so the next person does not repeat it." />

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
    <textarea
        id="{{ $fieldId }}"
        name="{{ $name }}"
        rows="{{ $rows }}"
        @if ($required) required @endif
        @if ($hasError) aria-invalid="true" @endif
        @if ($describedBy !== '') aria-describedby="{{ $describedBy }}" @endif
        {{ $attributes->merge([
            'class' => 'block w-full rounded-md border-slate-300 text-sm shadow-sm
                        focus:border-brand-500 focus:ring-brand-500
                        disabled:bg-slate-50 disabled:text-slate-500
                        '.($hasError ? 'border-red-400 focus:border-red-500 focus:ring-red-500' : ''),
        ]) }}
    >{{ old($errorKey, $value) }}</textarea>
</x-form.field>
