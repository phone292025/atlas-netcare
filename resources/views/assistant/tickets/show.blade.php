<x-app-layout>
    <x-slot name="title">{{ $ticket->ticket_number }}</x-slot>

    @include('tickets._detail', [
        'ticket' => $ticket,
        'comments' => $comments,
        'nextStates' => $nextStates,
        'back' => route('assistant.tickets.index'),
        'backLabel' => 'My tickets',
    ])
</x-app-layout>
