@props(['name', 'label' => null, 'help' => null, 'required' => false, 'bag' => 'default'])

{{--
    The label / help / error scaffolding around a form control.

    Internal. Pages use <x-form.input>, <x-form.select> or <x-form.textarea>;
    each renders its own control inside this wrapper. The wrapper exists so the
    three cannot drift apart — one place decides where the label sits, what an
    error looks like, and how the control is described to a screen reader.

    Ids and error keys come from App\Support\FormField so this file and the
    control inside it derive them identically. Two places deriving an id
    differently is how a label silently stops focusing its input.

    ACCESSIBILITY, which is NFR-10 and gets checked during UAT:
      - `for` / `id` pair the label to the control, so tapping the label
        focuses it — which matters on a phone during a lab round
      - `aria-describedby` points at the help text and the error, so a screen
        reader reads them as part of the field rather than as stray paragraphs
      - `aria-invalid` marks the field itself as failing, not just the message

    @see docs/08-ui-conventions.md — Blade components
    @see docs/03-requirements.md NFR-10
--}}

@php
    $fieldId = \App\Support\FormField::id($name);
    $hasError = $errors->getBag($bag)->has(\App\Support\FormField::errorKey($name));
@endphp

<div {{ $attributes->merge(['class' => 'space-y-1.5']) }}>
    @if (filled($label))
        <label for="{{ $fieldId }}" class="block text-sm font-medium text-slate-700">
            {{ $label }}
            @if ($required)
                <span class="text-red-600" aria-hidden="true">*</span>
                <span class="sr-only">(required)</span>
            @endif
        </label>
    @endif

    {{-- The control renders here. It attaches the id and aria attributes
         itself, using the same FormField derivations this block used. --}}
    {{ $slot }}

    @if (filled($help))
        <p id="{{ $fieldId }}-help" class="text-xs text-slate-500">{{ $help }}</p>
    @endif

    @if ($hasError)
        {{-- One message, not all of them. A field showing four stacked errors
             for the same value is noise; the first is the one to fix. --}}
        <p id="{{ $fieldId }}-error" class="flex items-start gap-1 text-xs text-red-600">
            <x-icon name="x-circle" class="w-3.5 h-3.5 flex-none mt-px" />
            <span>{{ $errors->getBag($bag)->first(\App\Support\FormField::errorKey($name)) }}</span>
        </p>
    @endif
</div>
