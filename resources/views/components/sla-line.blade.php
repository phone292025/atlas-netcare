@props(['ticket'])

{{--
    A ticket's time against its target, drawn as a line.

    The first stretch is measured from when the ticket opened. Past the
    target it is the target itself, followed by how far past it the ticket
    went, in red. Inside the target it is the time taken so far, followed by
    what is left, or for a finished ticket the time to spare. Each line is
    scaled to its own ticket, so a four-hour target and a three-day one fill
    the same width: the line shows how far through or how far past, and the
    labels say how long.

    The sentence from <x-ticket-due> rides along for screen readers, which
    cannot see a line.
--}}

@php
    $due = $ticket->due_at;
    $opened = $ticket->opened_at;
    $open = $ticket->status->isOutstanding();
    $finished = $ticket->resolved_at ?? $ticket->closed_at;

    // Whole units, the scale a queue is read at.
    $span = fn (int $minutes): string => match (true) {
        $minutes < 60 => max(1, $minutes).' min',
        $minutes < 48 * 60 => round($minutes / 60).' h',
        default => round($minutes / 1440).' d',
    };

    // [label classes, line classes], written out whole so Tailwind finds them.
    $tones = [
        'quiet' => ['text-fg-subtle', 'border-line-strong'],
        'running' => ['text-fg-muted', 'border-brand-500'],
        'soon' => ['font-medium text-amber-700', 'border-amber-500'],
        'made' => ['text-emerald-700', 'border-emerald-500'],
        'late' => ['font-medium text-red-700', 'border-red-500 border-b-2'],
    ];

    $segments = [];

    if ($due !== null && $opened !== null) {
        $end = $open || $finished === null ? now() : $finished;
        $target = max(1, (int) round($opened->diffInMinutes($due, true)));
        $taken = (int) round($opened->diffInMinutes($end, true));

        if ($taken > $target) {
            $segments = [
                [$target, $span($target), 'quiet'],
                [$taken - $target, $span($taken - $target).' past', 'late'],
            ];
        } elseif ($open) {
            $left = $target - $taken;
            $segments = [
                [max(1, $taken), $span($taken), $left <= 60 ? 'soon' : 'running'],
                [max(1, $left), $span($left).' left', $left <= 60 ? 'soon' : 'quiet'],
            ];
        } else {
            $segments = [
                [max(1, $taken), $span($taken), 'made'],
                [max(1, $target - $taken), $span($target - $taken).' to spare', 'quiet'],
            ];
        }
    }
@endphp

@if ($segments === [])
    <x-ticket-due :ticket="$ticket" {{ $attributes }} />
@else
    <span {{ $attributes->merge(['class' => 'block w-full max-w-[15rem]']) }}>
        <span class="flex items-end" aria-hidden="true">
            @foreach ($segments as $index => [$minutes, $label, $tone])
                <span class="relative min-w-[2.75rem] whitespace-nowrap pb-2 text-center text-2xs tabular-nums {{ $tones[$tone][0] }}"
                      style="flex: {{ $minutes }} 1 0%">{{ $label }}<span class="absolute inset-x-0 bottom-0 h-1.5 border-b {{ $index === 0 ? 'border-x' : 'border-r' }} {{ $tones[$tone][1] }}"></span></span>
            @endforeach
        </span>
        <x-ticket-due :ticket="$ticket" class="sr-only" />
    </span>
@endif
