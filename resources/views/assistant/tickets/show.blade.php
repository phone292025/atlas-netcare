<x-app-layout>
    <x-slot name="title">{{ $ticket->ticket_number }}</x-slot>

    <x-page-header :title="$ticket->title" :subtitle="$ticket->ticket_number.' · raised '.$ticket->opened_at?->diffForHumans()">
        <x-slot:actions>
            <a href="{{ route('assistant.tickets.index') }}"
               class="rounded-md bg-white px-3 py-2 text-sm font-medium text-slate-700 ring-1 ring-slate-300 hover:bg-slate-50">
                My tickets
            </a>
        </x-slot:actions>
    </x-page-header>

    @include('tickets._detail', ['ticket' => $ticket, 'comments' => $comments, 'nextStates' => $nextStates])
</x-app-layout>
