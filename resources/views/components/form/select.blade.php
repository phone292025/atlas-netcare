@props([
    'name',
    'label' => null,
    'help' => null,
    'options' => [],
    'selected' => null,
    'placeholder' => null,
    'blank' => null,
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

    A NESTED map renders as groups — the key becomes the <optgroup> label:

        ['Level 6 IT Lab 01' => [12 => 'APU-1007', 13 => 'APU-1008'], ...]

    Native grouping, no JavaScript: it works on a phone, with a keyboard, and
    with a screen reader, which a filtered-by-Alpine select would not.

    `placeholder` renders a DISABLED first option — use it when no value is a
    valid answer ("Choose a location"), not as a fake empty choice on a
    required field.

    `blank` renders a SELECTABLE first option with an empty value — for filter
    bars, where "any" is a real answer and the user must be able to get back to
    it. Use one or the other, never both:

        placeholder → "Choose a location"   (a form; you must pick something)
        blank       → "Anywhere"            (a filter; not picking is allowed)

    They are separate props rather than one prop with a flag because the wrong
    one is invisible until someone tries to clear a filter and finds the option
    greyed out.

    @see App\Enums\Concerns\HasValues::options()
    @see docs/06-ui-conventions.md — Blade components
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
        @elseif (filled($blank))
            <option value="" @selected($current === null || $current === '')>
                {{ $blank }}
            </option>
        @endif

        @foreach ($options as $optionValue => $optionLabel)
            @if (is_array($optionLabel))
                <optgroup label="{{ $optionValue }}">
                    @foreach ($optionLabel as $groupedValue => $groupedLabel)
                        <option value="{{ $groupedValue }}" @selected((string) $current === (string) $groupedValue)>
                            {{ $groupedLabel }}
                        </option>
                    @endforeach
                </optgroup>
            @else
                <option value="{{ $optionValue }}" @selected((string) $current === (string) $optionValue)>
                    {{ $optionLabel }}
                </option>
            @endif
        @endforeach
    </select>
</x-form.field>
