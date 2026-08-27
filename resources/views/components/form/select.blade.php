@props([
    'name',
    'label' => null,
    'help' => null,
    'options' => [],
    'selected' => null,
    'placeholder' => null,
    'required' => false,
    'bag' => 'default',
])

{{--
    A dropdown, wired to its label, help text and validation error.

    `options` is a value => label map, which is exactly the shape every enum in
    this system already produces:

        <x-form.select name="priority" label="Priority"
                       :options="App\Enums\TicketPriority::options()"
                       :selected="$ticket->priority?->value" />

    That is the whole point of HasValues::options(). A form and a table badge
    read their labels from the same enum, so they cannot disagree about what a
    value is called.

    `placeholder` renders a disabled first option — use it when no value is a
    valid answer ("Choose a location"), not as a fake empty choice on a
    required field.

    @see App\Enums\Concerns\HasValues::options()
    @see docs/08-ui-conventions.md — Blade components
--}}

@php
    $fieldId = \App\Support\FormField::id($name);
    $errorKey = \App\Support\FormField::errorKey($name);
    $hasError = $errors->getBag($bag)->has($errorKey);

    $current = old($errorKey, $selected);

    $describedBy = implode(' ', array_filter([
        filled($help) ? $fieldId.'-help' : null,
        $hasError ? $fieldId.'-error' : null,
    ]));
@endphp

<x-form.field :name="$name" :label="$label" :help="$help" :required="$required" :bag="$bag">
    <select
        id="{{ $fieldId }}"
        name="{{ $name }}"
        @if ($required) required @endif
        @if ($hasError) aria-invalid="true" @endif
        @if ($describedBy !== '') aria-describedby="{{ $describedBy }}" @endif
        {{ $attributes->merge([
            'class' => 'block w-full rounded-md border-slate-300 text-sm shadow-sm
                        focus:border-brand-500 focus:ring-brand-500
                        disabled:bg-slate-50 disabled:text-slate-500
                        '.($hasError ? 'border-red-400 focus:border-red-500 focus:ring-red-500' : ''),
        ]) }}
    >
        @if (filled($placeholder))
            <option value="" disabled @selected($current === null || $current === '')>
                {{ $placeholder }}
            </option>
        @endif

        @foreach ($options as $optionValue => $optionLabel)
            <option value="{{ $optionValue }}" @selected((string) $current === (string) $optionValue)>
                {{ $optionLabel }}
            </option>
        @endforeach
    </select>
</x-form.field>
