@props(['title', 'message' => null, 'icon' => 'inbox'])

{{--
    What a list shows when it has nothing in it. Every index view needs one:
    a blank page cannot be told apart from a broken one, and a clean install
    starts with empty tables.

    Say what would put something here, and give them the way to do it:

        <x-empty-state title="No tickets yet"
                       message="Tickets raised by assistants during lab rounds appear here."
                       icon="ticket">
            <x-slot:action>
                <a href="{{ route('assistant.tickets.create') }}">Raise the first ticket</a>
            </x-slot:action>
        </x-empty-state>

    @see docs/06-ui-conventions.md — "every list view needs a real <x-empty-state>"
--}}

<div {{ $attributes->merge(['class' => 'px-6 py-14 text-center']) }}>
    <div class="mx-auto grid size-11 place-items-center rounded-xl border border-line bg-sunken text-fg-subtle">
        <x-icon :name="$icon" class="size-5" />
    </div>

    <h3 class="mt-4 text-sm font-semibold text-fg">{{ $title }}</h3>

    @if (filled($message))
        <p class="mx-auto mt-1.5 max-w-sm text-sm leading-relaxed text-fg-muted">{{ $message }}</p>
    @endif

    @isset($action)
        <div class="mt-6 flex justify-center gap-2">{{ $action }}</div>
    @endisset
</div>
