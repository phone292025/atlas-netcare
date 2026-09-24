@props(['ticket'])

{{--
    When a ticket is due, in words a queue can be scanned by: "Overdue by
    3 hours" in red, "Due in 40 minutes" in amber inside the last hour, and
    plain "Due in 2 days" otherwise. A finished ticket says whether it made
    its target, which is the question anyone looking back is asking.
--}}

@php
    $due = $ticket->due_at;
    $open = $ticket->status->isOutstanding();
    $span = fn () => $due->diffForHumans(['syntax' => \Carbon\CarbonInterface::DIFF_ABSOLUTE, 'parts' => 1]);
    $finished = $ticket->resolved_at ?? $ticket->closed_at;
@endphp

@if ($due === null)
    <span {{ $attributes->merge(['class' => 'text-fg-subtle']) }}>No target</span>
@elseif (! $open)
    @if ($finished && $finished->lte($due))
        <span {{ $attributes->merge(['class' => 'inline-flex items-center gap-1 text-emerald-700']) }}>
            <x-icon name="check" class="size-3.5" /> Made its target
        </span>
    @else
        <span {{ $attributes->merge(['class' => 'text-fg-muted']) }}>Finished after its target</span>
    @endif
@elseif ($due->isPast())
    <span {{ $attributes->merge(['class' => 'inline-flex items-center gap-1 font-medium text-red-700']) }}>
        <x-icon name="clock" class="size-3.5" />
        Overdue by {{ $span() }}
    </span>
@elseif ($due->lt(now()->addHour()))
    <span {{ $attributes->merge(['class' => 'inline-flex items-center gap-1 font-medium text-amber-700']) }}>
        <x-icon name="clock" class="size-3.5" />
        Due in {{ $span() }}
    </span>
@else
    <span {{ $attributes->merge(['class' => 'text-fg-muted']) }}>Due in {{ $span() }}</span>
@endif
