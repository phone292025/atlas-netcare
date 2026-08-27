@props(['title', 'message' => null, 'icon' => 'inbox'])

{{--
    What a list shows when it has nothing in it.

    This is not decoration. ATLAS_DEMO_MODE=false and `atlas:purge-demo-data`
    both leave a working system with empty tables, and a marker or a real user
    looking at a blank page cannot tell "nothing here yet" from "this is
    broken". Every index view needs one of these — it is the difference between
    the app looking deliberate and looking unfinished.

    Say what would put something here, and give them the way to do it:

        <x-empty-state title="No tickets yet"
                       message="Tickets raised by assistants during lab rounds appear here."
                       icon="ticket">
            <x-slot:action>
                <a href="{{ route('assistant.tickets.create') }}">Raise the first ticket</a>
            </x-slot:action>
        </x-empty-state>

    @see docs/08-ui-conventions.md — "every list view needs a real <x-empty-state>"
--}}

<div {{ $attributes->merge(['class' => 'px-6 py-12 text-center']) }}>
    <div class="mx-auto grid place-items-center w-12 h-12 rounded-full bg-slate-100 text-slate-400">
        <x-icon :name="$icon" class="w-6 h-6" />
    </div>

    <h3 class="mt-4 text-sm font-semibold text-slate-900">{{ $title }}</h3>

    @if (filled($message))
        <p class="mx-auto mt-1 max-w-sm text-sm text-slate-500">{{ $message }}</p>
    @endif

    @isset($action)
        <div class="mt-6 flex justify-center gap-2">{{ $action }}</div>
    @endisset
</div>
