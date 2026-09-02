@props(['name', 'title' => null, 'maxWidth' => 'lg', 'show' => false])

{{--
    An Alpine-driven dialog, opened by name from anywhere on the page.

    Every destructive action in this system confirms through one of these. A
    bare delete button is never acceptable — see the interaction conventions.

    Open and close it by dispatching a window event with the modal's name:

        <button @click="$dispatch('open-modal', 'confirm-deactivate')">Deactivate</button>

        <x-modal name="confirm-deactivate" title="Deactivate this account?">
            <p>They will be signed out and cannot sign in again. Their tickets
               and inspection history are kept.</p>
            <x-slot:footer>
                <button @click="$dispatch('close-modal', 'confirm-deactivate')">Cancel</button>
                <button form="deactivate-form">Deactivate account</button>
            </x-slot:footer>
        </x-modal>

    The name is what routes the event, so it must be unique on the page. Prefer
    a verb and a subject — `confirm-deactivate`, not `modal-1`.

    This replaces Breeze's modal. The focus trap is kept from it, because
    getting tab containment right is fiddly and theirs is correct; the header,
    footer slot, close button and dialog semantics are new.

    @see docs/06-ui-conventions.md — Interaction conventions
--}}

@php
    $maxWidthClass = [
        'sm' => 'sm:max-w-sm',
        'md' => 'sm:max-w-md',
        'lg' => 'sm:max-w-lg',
        'xl' => 'sm:max-w-xl',
        '2xl' => 'sm:max-w-2xl',
    ][$maxWidth] ?? 'sm:max-w-lg';
@endphp

<div
    x-data="{
        show: @js($show),
        focusables() {
            let selector = 'a, button, input:not([type=\'hidden\']), textarea, select, details, [tabindex]:not([tabindex=\'-1\'])'
            return [...$el.querySelectorAll(selector)].filter(el => ! el.hasAttribute('disabled'))
        },
        firstFocusable() { return this.focusables()[0] },
        lastFocusable() { return this.focusables().slice(-1)[0] },
        nextFocusable() { return this.focusables()[this.nextFocusableIndex()] || this.firstFocusable() },
        prevFocusable() { return this.focusables()[this.prevFocusableIndex()] || this.lastFocusable() },
        nextFocusableIndex() { return (this.focusables().indexOf(document.activeElement) + 1) % (this.focusables().length + 1) },
        prevFocusableIndex() { return Math.max(0, this.focusables().indexOf(document.activeElement)) - 1 },
    }"
    x-init="$watch('show', value => {
        // The page behind a dialog must not scroll under it.
        document.body.classList.toggle('overflow-y-hidden', value)
        if (value) setTimeout(() => firstFocusable()?.focus(), 100)
    })"
    x-on:open-modal.window="$event.detail == '{{ $name }}' ? show = true : null"
    x-on:close-modal.window="$event.detail == '{{ $name }}' ? show = false : null"
    x-on:close.stop="show = false"
    x-on:keydown.escape.window="show = false"
    x-on:keydown.tab.prevent="$event.shiftKey || nextFocusable().focus()"
    x-on:keydown.shift.tab.prevent="prevFocusable().focus()"
    x-show="show"
    class="fixed inset-0 z-50 overflow-y-auto px-4 py-6 sm:py-16"
    x-cloak
>
    <div x-show="show"
         x-transition.opacity
         class="fixed inset-0 bg-slate-900/50"
         x-on:click="show = false"></div>

    <div x-show="show"
         x-transition
         role="dialog"
         aria-modal="true"
         @if (filled($title)) aria-labelledby="modal-title-{{ $name }}" @endif
         class="relative mx-auto w-full {{ $maxWidthClass }} bg-white rounded-lg shadow-xl ring-1 ring-slate-200">

        @if (filled($title))
            <div class="flex items-start justify-between gap-4 px-6 py-4 border-b border-slate-200">
                <h2 id="modal-title-{{ $name }}" class="text-base font-semibold text-slate-900">
                    {{ $title }}
                </h2>
                <button type="button"
                        x-on:click="show = false"
                        class="-mr-2 -mt-1 p-1 rounded text-slate-400 hover:text-slate-600
                               focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500"
                        aria-label="Close dialog">
                    <x-icon name="close" class="w-5 h-5" />
                </button>
            </div>
        @endif

        <div class="px-6 py-5 text-sm text-slate-600">
            {{ $slot }}
        </div>

        @isset($footer)
            <div class="flex flex-wrap justify-end gap-2 px-6 py-4 border-t border-slate-200 bg-slate-50/60 rounded-b-lg">
                {{ $footer }}
            </div>
        @endisset
    </div>
</div>
