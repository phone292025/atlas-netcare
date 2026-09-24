@props(['name' => null, 'size' => 'sm'])

{{--
    A person's initials in a rounded square: <x-avatar :name="$ticket->assignee?->name" />
    An empty name renders a dashed placeholder, for "nobody yet".
--}}

@php
    $box = match ($size) {
        'xl' => 'size-14 rounded-2xl text-lg',
        'lg' => 'size-10 rounded-xl text-sm',
        'md' => 'size-8 rounded-lg text-xs',
        default => 'size-6 rounded-md text-[10px]',
    };
    $initials = collect(preg_split('/\s+/', trim((string) $name)))->filter()->take(2)
        ->map(fn ($word) => mb_strtoupper(mb_substr($word, 0, 1)))->implode('');
@endphp

@if ($initials === '')
    <span {{ $attributes->merge(['class' => "inline-block flex-none border border-dashed border-line-strong {$box}"]) }} aria-hidden="true"></span>
@else
    <span {{ $attributes->merge(['class' => "inline-grid flex-none place-items-center bg-brand-100 font-semibold text-brand-800 {$box}"]) }}
          aria-hidden="true">{{ $initials }}</span>
@endif
