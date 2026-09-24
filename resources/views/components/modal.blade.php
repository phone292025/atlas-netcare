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
        // The first field to type in, else the first control. Focusing the
        // close button left the cursor out of the one box a dialog asks for.
        initialFocus() {
            return $el.querySelector('textarea, select, input:not([type=hidden]):not([type=checkbox]):not([type=radio])')
                || this.firstFocusable()
        },
        lastFocusable() { return this.focusables().slice(-1)[0] },
        nextFocusable() { return this.focusables()[this.nextFocusableIndex()] || this.firstFocusable() },
        prevFocusable() { return this.focusables()[this.prevFocusableIndex()] || this.lastFocusable() },
        nextFocusableIndex() { return (this.focusables().indexOf(document.activeElement) + 1) % (this.focusables().length + 1) },
        prevFocusableIndex() { return Math.max(0, this.focusables().indexOf(document.activeElement)) - 1 },
    }"
    x-init="$watch('show', value => {
        // The page behind a dialog must not scroll under it.
        document.body.classList.toggle('overflow-y-hidden', value)
        if (value) setTimeout(() => initialFocus()?.focus(), 100)
    })"
    x-on:open-modal.window="$event.detail == '{{ $name }}' ? show = true : null"
    x-on:close-modal.window="$event.detail == '{{ $name }}' ? show = false : null"
    x-on:close.stop="show = false"
    x-on:keydown.escape.window="show = false"
    x-on:keydown.tab.prevent="$event.shiftKey || nextFocusable().focus()"
    x-on:keydown.shift.tab.prevent="prevFocusable().focus()"
    x-show="show"
    class="fixed inset-0 z-modal overflow-y-auto"
    x-cloak
>
    <div x-show="show"
         x-transition.opacity.duration.150ms
         class="fixed inset-0 bg-[rgb(var(--shadow)/0.45)]"
         x-on:click="show = false"></div>

    {{-- A bottom sheet on a phone, a centred dialog from sm up. --}}
    <div class="relative flex min-h-full items-end justify-center sm:items-start sm:p-6 sm:pt-[12vh]"
         x-on:click.self="show = false">
        <div x-show="show"
             x-transition:enter="transition duration-200 ease-out"
             x-transition:enter-start="translate-y-4 opacity-0 sm:translate-y-1 sm:scale-[0.98]"
             x-transition:enter-end="translate-y-0 opacity-100 sm:scale-100"
             x-transition:leave="transition duration-150 ease-in"
             x-transition:leave-start="opacity-100"
             x-transition:leave-end="translate-y-2 opacity-0"
             role="dialog"
             aria-modal="true"
             @if (filled($title)) aria-labelledby="modal-title-{{ $name }}" @endif
             class="relative w-full {{ $maxWidthClass }} rounded-t-2xl border border-line bg-raised pb-[env(safe-area-inset-bottom)] shadow-lg sm:rounded-2xl sm:pb-0">

            @if (filled($title))
                <div class="flex items-start justify-between gap-4 px-6 pb-1 pt-5">
                    <h2 id="modal-title-{{ $name }}" class="text-[17px] font-semibold tracking-tight text-fg">
                        {{ $title }}
                    </h2>
                    <button type="button"
                            x-on:click="show = false"
                            class="btn btn-ghost btn-icon btn-sm -mr-2 -mt-1"
                            aria-label="Close dialog">
                        <x-icon name="close" class="size-4" />
                    </button>
                </div>
            @endif

            <div class="px-6 py-4 text-sm leading-relaxed text-fg-muted">
                {{ $slot }}
            </div>

            @isset($footer)
                <div class="flex flex-wrap justify-end gap-2 px-6 pb-5 pt-2">
                    {{ $footer }}
                </div>
            @endisset
        </div>
    </div>
</div>
