@props(['navigation'])

{{--
    The role's navigation, in the groups config('atlas.navigation') names.
    Built once from config, so nobody hand-writes a menu.

    An entry whose route is not registered renders struck through while
    APP_DEBUG is on, so a mistyped name is visible during development rather
    than silently missing, and is hidden when debug is off.
--}}

<nav class="space-y-5 pt-2" aria-label="Main navigation">
    @foreach ($navigation as $group => $entries)
        <div>
            @if (filled($group))
                <p class="kicker px-2.5 pb-1.5">{{ $group }}</p>
            @endif

            <ul class="space-y-0.5">
                @foreach ($entries as $entry)
                    <li>
                        @if ($entry['exists'])
                            <a href="{{ route($entry['route']) }}"
                               @if ($entry['active']) aria-current="page" @endif
                               class="group flex h-9 items-center gap-3 rounded-md px-2.5 text-sm transition
                                      {{ $entry['active']
                                          ? 'bg-brand-50 font-medium text-brand-800'
                                          : 'text-fg-muted hover:bg-sunken hover:text-fg' }}">
                                <x-icon :name="$entry['icon']" :fill="$entry['active']"
                                        class="size-[18px] flex-none {{ $entry['active'] ? 'text-brand-600' : 'text-fg-subtle group-hover:text-fg-muted' }}" />
                                <span class="truncate">{{ $entry['label'] }}</span>
                            </a>
                        @elseif (config('app.debug'))
                            <span class="flex h-9 items-center gap-3 rounded-md px-2.5 text-sm text-fg-subtle"
                                  title="Route {{ $entry['route'] }} is not registered yet">
                                <x-icon :name="$entry['icon']" class="size-[18px] flex-none" />
                                <span class="truncate line-through">{{ $entry['label'] }}</span>
                                <span class="kicker ml-auto">soon</span>
                            </span>
                        @endif
                    </li>
                @endforeach
            </ul>
        </div>
    @endforeach
</nav>
