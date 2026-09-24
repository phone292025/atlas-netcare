@props(['status'])

{{--
    Any enum implementing App\Enums\Contracts\HasTone, as a badge with a
    status dot: <x-status-badge :status="$ticket->status" />
--}}

<x-badge :tone="$status->tone()" dot {{ $attributes }}>
    {{ $status->label() }}
</x-badge>
