<x-app-layout>
    <x-slot name="title">Notifications</x-slot>

    @php
        // Grouped by day on the campus clock, newest first, so "yesterday"
        // means yesterday where the reader is standing.
        $today = \App\Support\CampusClock::today();
        $groups = $alarms->groupBy(function ($alarm) use ($today) {
            $day = $alarm->trigger_at->campus()->startOfDay();

            return match (true) {
                $day->isSameDay($today) => 'Today',
                $day->isSameDay($today->copy()->subDay()) => 'Yesterday',
                $day->greaterThan($today->copy()->subDays(7)) => 'Earlier this week',
                default => 'Older',
            };
        });
        $unread = $alarms->filter->isUnread()->count();
    @endphp

    <div class="mx-auto max-w-3xl">
        <x-page-header title="Notifications"
                       :subtitle="$unread > 0
                           ? $unread.' new since you last looked. Opening this page marks them read.'
                           : 'Shift reminders, inspection rounds, and anything raised for you.'" />

        @if ($alarms->isEmpty())
            <x-card>
                <x-empty-state title="Nothing yet" icon="bell"
                               message="Shift reminders and alerts raised for you will appear here." />
            </x-card>
        @else
            <div class="space-y-6">
                @foreach ($groups as $label => $items)
                    <section aria-labelledby="group-{{ Str::slug($label) }}">
                        <h3 id="group-{{ Str::slug($label) }}" class="kicker mb-2 px-1">{{ $label }}</h3>
                        <ul class="divide-y divide-line overflow-hidden rounded-xl border border-line bg-surface shadow-xs">
                            @foreach ($items as $alarm)
                                <li>
                                    <a href="{{ route('notifications.show', $alarm) }}"
                                       @class([
                                           'group flex items-start gap-3 px-4 py-3.5 transition hover:bg-sunken/60 sm:px-5',
                                           'bg-brand-50/40' => $alarm->isUnread(),
                                       ])>
                                        <span class="grid size-9 flex-none place-items-center rounded-lg ring-1 ring-inset {{ \App\Support\Tone::badge($alarm->type->tone()) }}">
                                            <x-icon :name="$alarm->type->icon()" class="size-[18px]" />
                                        </span>

                                        <span class="min-w-0 flex-1">
                                            <span class="flex items-start justify-between gap-3">
                                                <span @class(['text-sm text-fg', 'font-semibold' => $alarm->isUnread(), 'font-medium' => ! $alarm->isUnread()])>{{ $alarm->title }}</span>
                                                <time datetime="{{ $alarm->trigger_at->toIso8601String() }}"
                                                      title="{{ $alarm->trigger_at->campus()->format('D j M Y, H:i') }}"
                                                      class="flex-none whitespace-nowrap text-xs text-fg-subtle">{{ $alarm->trigger_at->diffForHumans(['short' => true]) }}</time>
                                            </span>
                                            @if (filled($alarm->message))
                                                <span class="mt-0.5 block text-sm text-fg-muted">{{ $alarm->message }}</span>
                                            @endif
                                            <span class="mt-1 flex items-center gap-1.5 text-xs text-fg-subtle">
                                                {{-- The unread dot is the only thing separating
                                                     new from old here, since opening the list
                                                     marks everything read. --}}
                                                @if ($alarm->isUnread())
                                                    <span class="size-1.5 rounded-full bg-brand-600"></span>
                                                    <span class="sr-only">New.</span>
                                                @endif
                                                {{ $alarm->type->label() }}
                                            </span>
                                        </span>
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    </section>
                @endforeach
            </div>
        @endif
    </div>
</x-app-layout>
