@props(['item', 'result' => null, 'remark' => null, 'namePrefix' => null])

{{--
    One question on a lab PC inspection: OK, Issue, or N/A, with a remark that
    appears when there is something to say.

    THIS IS THE MOST-USED SCREEN IN THE SYSTEM. An assistant works down a rack
    of machines on a phone, one hand, standing up. NFR-05 says it must be
    operable that way and UAT tests it at 375px, so:

      - the three choices are full-height tap targets, not 16px radio dots
      - they sit in a row that still fits at 375px, and never wrap mid-word
      - the whole control is a <label>, so the tap area is the box, not the dot
      - nothing here needs a second hand or a precise tap

    A CRITICAL item failing makes the whole inspection a `fail` rather than
    `pass_with_issues`, so it is marked — the assistant should know that before
    they tap, not after.

    The remark expands only for Issue. Requiring a remark on every OK would
    double the taps for a clean machine, and a rack of 30 clean machines is the
    normal case.

    Usage, inside the inspection form:
        @foreach ($template->items as $item)
            <x-checklist-item :item="$item"
                              :result="old("results.{$item->id}.result")"
                              name-prefix="results[{{ $item->id }}]" />
        @endforeach

    `item` is any object with `id`, `label` and `is_critical`. The real model
    arrives with M3; this component does not care which, and must not — it
    receives data, it never fetches it.

    @see docs/modules/M3-checklists-and-inspections.md
    @see docs/03-requirements.md FR-205, NFR-05
--}}

@php
    $prefix = $namePrefix ?? 'results['.($item->id ?? 0).']';
    $resultName = $prefix.'[result]';
    $remarkName = $prefix.'[remark]';

    $resultId = \App\Support\FormField::id($resultName);
    $errorKey = \App\Support\FormField::errorKey($resultName);
    $hasError = $errors->has($errorKey);

    $current = old(\App\Support\FormField::errorKey($resultName), $result);
    $isCritical = (bool) ($item->is_critical ?? false);
@endphp

<div x-data="{ result: @js((string) $current) }"
     {{ $attributes->merge([
         'class' => 'rounded-lg bg-white p-4 ring-1 '
             .($hasError ? 'ring-red-300' : 'ring-slate-200'),
     ]) }}>

    <div class="flex items-start justify-between gap-3">
        <p class="text-sm font-medium text-slate-900">{{ $item->label ?? '' }}</p>

        @if ($isCritical)
            <x-badge tone="urgent">Critical</x-badge>
        @endif
    </div>

    {{-- Three tap targets. `flex-1` so they share the width evenly and stay
         comfortably wide at 375px. --}}
    <div class="mt-3 flex gap-2" role="radiogroup" aria-labelledby="{{ $resultId }}-label">
        <span id="{{ $resultId }}-label" class="sr-only">{{ $item->label ?? 'Result' }}</span>

        @foreach (\App\Enums\ChecklistResult::cases() as $choice)
            <label class="flex-1 cursor-pointer select-none">
                <input type="radio"
                       name="{{ $resultName }}"
                       value="{{ $choice->value }}"
                       x-model="result"
                       class="peer sr-only"
                       @checked($current === $choice->value)>

                {{-- The checked classes come from Tone::choice() as literal
                     `peer-checked:` strings. Building them at runtime by
                     prefixing Tone::badge() would produce class names Tailwind
                     never sees in a scanned file, and every selected choice
                     would render colourless. --}}
                <span class="flex items-center justify-center gap-1.5 rounded-md px-2 py-3
                             text-sm font-medium ring-1 ring-inset
                             text-slate-600 ring-slate-200 bg-white
                             peer-focus-visible:ring-2 peer-focus-visible:ring-brand-500
                             {{ \App\Support\Tone::choice($choice->tone()) }}">
                    {{ $choice->label() }}
                </span>
            </label>
        @endforeach
    </div>

    @error($errorKey)
        <p class="mt-2 flex items-start gap-1 text-xs text-red-600">
            <x-icon name="x-circle" class="w-3.5 h-3.5 flex-none mt-px" />
            <span>{{ $message }}</span>
        </p>
    @enderror

    {{-- Only for Issue. A clean machine should be three taps, not six. --}}
    {{-- x-show, not the x-collapse plugin: @alpinejs/collapse is not installed
         and adding a package needs an ADR. A plain show is enough here. --}}
    <div x-show="result === '{{ \App\Enums\ChecklistResult::Issue->value }}'"
         x-cloak
         class="mt-3">
        <x-form.textarea :name="$remarkName"
                         label="What is wrong?"
                         rows="2"
                         :value="$remark"
                         help="One line is enough. This becomes the ticket description." />
    </div>
</div>
