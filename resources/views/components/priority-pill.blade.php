@props(['priority'])

{{--
    Ticket priority, rendered as a pill so it reads as a different kind of thing
    from a status badge sitting next to it.

    This is the element that carries URGENCY. Ticket status carries progress and
    never uses the urgent tone — otherwise a brand-new low-priority ticket would
    render the same red as an offline access point.

    Usage:  <x-priority-pill :priority="$ticket->priority" />

    @see docs/08-ui-conventions.md — "Status carries progress; priority carries urgency"
--}}

<x-badge :tone="$priority->tone()" pill {{ $attributes }}>
    {{ $priority->label() }}
</x-badge>
