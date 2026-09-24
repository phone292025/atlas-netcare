<x-app-layout>
    <x-slot name="title">{{ $ticket->ticket_number }}</x-slot>

    @include('tickets._detail', [
        'ticket' => $ticket,
        'comments' => $comments,
        'nextStates' => $nextStates,
        'back' => route('engineer.tickets.index'),
        'backLabel' => 'Ticket queue',
    ])
</x-app-layout>
