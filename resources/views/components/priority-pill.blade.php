@props(['priority'])

{{--
    A ticket's priority: a three-bar signal, filled to the priority's weight,
    and the label, in the priority's tone. Status carries progress, priority
    carries urgency, so the two never look alike.

        <x-priority-pill :priority="$ticket->priority" />
--}}

@php
    $weight = $priority->weight();
    $accent = \App\Support\Tone::accent($priority->tone());
@endphp

<span {{ $attributes->merge(['class' => "inline-flex h-6 items-center gap-1.5 whitespace-nowrap text-xs font-medium {$accent}"]) }}>
    <span class="inline-flex h-3 items-end gap-[2px]" aria-hidden="true">
        @foreach ([1 => 'h-1.5', 2 => 'h-2.5', 3 => 'h-3'] as $bar => $height)
            <span class="w-[3px] rounded-[1px] {{ $height }} bg-current {{ $bar <= $weight ? '' : 'opacity-25' }}"></span>
        @endforeach
    </span>
    <span class="{{ $weight === 1 ? 'text-fg-muted' : '' }}">{{ $priority->label() }}</span>
</span>
