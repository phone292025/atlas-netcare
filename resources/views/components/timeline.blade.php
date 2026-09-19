@props(['events' => []])

{{--
    An ordered list of things that happened, with who did them and when.

    Two screens need this and they are the two where "who changed this, and
    when" is the whole question: a ticket's history, and the admin audit log
    (NFR-06 requires every significant state change to be attributable).

    `events` is a collection of arrays or objects carrying:

        actor        string   who did it            "Yar Zar Min"
        action       string   what happened         "resolved the ticket"
        at           Carbon   when                  now()
        tone         string   optional, colours the dot
        description  string   optional, a line underneath
        icon         string   optional, defaults to a plain dot

    Usage:
        <x-timeline :events="$ticket->history()" />

    ABSOLUTE TIME IS THE TITLE, RELATIVE TIME IS THE TEXT. "3 hours ago" is what
    someone reads at a glance; the exact timestamp is what they need when
    writing an incident up, so it is on hover and in the datetime attribute
    rather than lost.

    @see docs/modules/M4-ticketing.md
    @see docs/01-requirements.md NFR-06
--}}

@php
    $items = collect($events);
@endphp

@if ($items->isEmpty())
    <x-empty-state title="Nothing has happened yet"
                   message="Actions taken on this record will be listed here."
                   icon="inbox" />
@else
    <ol {{ $attributes->merge(['class' => 'space-y-0']) }}>
        @foreach ($items as $index => $event)
            @php
                $get = fn (string $key, $default = null) => is_array($event)
                    ? ($event[$key] ?? $default)
                    : ($event->{$key} ?? $default);

                $at = $get('at');
                $isLast = $index === $items->count() - 1;
            @endphp

            <li class="relative flex gap-3 pb-5">
                {{-- The connecting rule, stopped short on the last item so the
                     list ends rather than trailing off. --}}
                @unless ($isLast)
                    <span class="absolute left-[7px] top-5 -bottom-0 w-px bg-slate-200" aria-hidden="true"></span>
                @endunless

                <span class="relative z-10 mt-1.5 grid h-3.5 w-3.5 flex-none place-items-center
                             rounded-full ring-4 ring-white {{ \App\Support\Tone::solid($get('tone')) }}">
                    @if ($get('icon'))
                        <x-icon :name="$get('icon')" class="h-2.5 w-2.5 text-white" />
                    @endif
                </span>

                <div class="min-w-0 flex-1 -mt-0.5">
                    <p class="text-sm text-slate-900">
                        <span class="font-medium">{{ $get('actor', 'System') }}</span>
                        {{ $get('action') }}
                    </p>

                    @if (filled($get('description')))
                        <p class="mt-0.5 text-sm text-slate-500">{{ $get('description') }}</p>
                    @endif

                    @if ($at)
                        <time datetime="{{ $at->toIso8601String() }}"
                              title="{{ $at->campus()->format('D j M Y, H:i') }}"
                              class="mt-0.5 block text-xs text-slate-400">
                            {{ $at->diffForHumans() }}
                        </time>
                    @endif
                </div>
            </li>
        @endforeach
    </ol>
@endif
