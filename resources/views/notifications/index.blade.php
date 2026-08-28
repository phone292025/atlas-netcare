<x-app-layout>
    <x-slot name="title">Notifications</x-slot>

    <div class="mx-auto max-w-3xl">
        <x-page-header title="Notifications"
                       subtitle="Shift reminders, inspection rounds, and anything raised for you." />

        <x-card flush>
            @if ($alarms->isEmpty())
                <x-empty-state title="Nothing yet"
                               message="Shift reminders and alerts raised for you will appear here."
                               icon="bell" />
            @else
                <ul class="divide-y divide-slate-100">
                    @foreach ($alarms as $alarm)
                        <li>
                            <a href="{{ route('notifications.show', $alarm) }}"
                               class="flex items-start gap-3 px-6 py-4 hover:bg-slate-50">
                                {{-- The unread dot is the only thing separating
                                     new from old here, since opening the list
                                     marks everything read. --}}
                                <span class="mt-1.5 h-2 w-2 flex-none rounded-full
                                             {{ $alarm->isUnread() ? \App\Support\Tone::solid($alarm->type->tone()) : 'bg-slate-200' }}"></span>

                                <span class="min-w-0 flex-1">
                                    <span class="block text-sm font-medium text-slate-900">{{ $alarm->title }}</span>
                                    @if (filled($alarm->message))
                                        <span class="mt-0.5 block text-sm text-slate-600">{{ $alarm->message }}</span>
                                    @endif
                                    <span class="mt-1 block text-xs text-slate-400">
                                        {{ $alarm->type->label() }} ·
                                        <time datetime="{{ $alarm->trigger_at->toIso8601String() }}"
                                              title="{{ $alarm->trigger_at->format('D j M Y, H:i') }}">{{ $alarm->trigger_at->diffForHumans() }}</time>
                                    </span>
                                </span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-card>
    </div>
</x-app-layout>
