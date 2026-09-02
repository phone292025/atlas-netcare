@props(['status'])

{{--
    Renders any enum implementing App\Enums\Contracts\HasTone.

    The component asks the enum for its own label and tone, so a badge can never
    disagree with a dropdown or a chart legend about what a value is called or
    what colour it is.

    Usage:  <x-status-badge :status="$ticket->status" />
            <x-status-badge :status="$ap->status" />
            <x-status-badge :status="$alarm->type" />

    @see docs/06-ui-conventions.md
--}}

<x-badge :tone="$status->tone()" {{ $attributes }}>
    {{ $status->label() }}
</x-badge>
