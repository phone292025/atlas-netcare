<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    @include('layouts.partials.head')
</head>
<body class="min-h-[100dvh]">

{{--
    The signed-in shell.

    Desktop: a fixed sidebar with the role's navigation in groups, a slim top
    bar with the breadcrumb and notifications, and the page. Phone: the sidebar
    becomes a drawer, and an assistant, who works from a phone during lab
    rounds, also gets a tab bar at the bottom for their four main screens.

    Ctrl+K or / opens the command menu from anywhere.

    Data comes from App\View\Components\AppLayout; nothing here queries.
--}}

<a href="#main"
   class="sr-only focus:not-sr-only focus:fixed focus:left-4 focus:top-4 focus:z-toast focus:rounded-md focus:bg-raised focus:px-4 focus:py-2 focus:text-sm focus:font-medium focus:shadow-lg">
    Skip to content
</a>

<div x-data="{ navOpen: false }" @keydown.window.escape="navOpen = false">

    {{-- Drawer scrim, phone only --}}
    <div x-show="navOpen" x-transition.opacity @click="navOpen = false"
         class="fixed inset-0 z-drawer bg-[rgb(var(--shadow)/0.45)] lg:hidden" x-cloak></div>

    {{-- Sidebar. The translate classes are scoped to max-lg on purpose: without
         the variant, -translate-x-full and lg:translate-x-0 both set the same
         variable and the negative one wins, hiding the sidebar on desktop. --}}
    <aside class="fixed inset-y-0 left-0 z-drawer flex w-[260px] print:hidden flex-col border-r border-line bg-surface
                  max-lg:shadow-lg max-lg:transition-transform max-lg:duration-200 max-lg:ease-out"
           :class="navOpen ? 'max-lg:translate-x-0' : 'max-lg:-translate-x-full'"
           aria-label="Sidebar">

        <div class="flex h-14 flex-none items-center gap-2 px-4">
            <a href="{{ url('/') }}" class="rounded-md">
                <x-brand-mark />
            </a>
            <button type="button" @click="navOpen = false" class="btn btn-ghost btn-icon btn-sm ml-auto lg:hidden"
                    aria-label="Close navigation">
                <x-icon name="close" class="size-4" />
            </button>
        </div>

        <div class="px-3 pb-2">
            <button type="button" @click="$dispatch('open-command-menu')"
                    class="flex h-9 w-full items-center gap-2 rounded-md border border-line bg-sunken px-2.5 text-sm text-fg-subtle
                           transition hover:border-line-strong hover:text-fg-muted">
                <x-icon name="search" class="size-4" />
                <span>Search or jump to</span>
                <span class="kbd ml-auto max-lg:hidden">Ctrl K</span>
            </button>
        </div>

        <div class="flex-1 overflow-y-auto px-3 pb-4">
            <x-sidebar :navigation="$navigation" />
        </div>

        @auth
            @php
                $user = auth()->user();
                $role = $user->role;
                $initials = collect(explode(' ', trim($user->name)))->filter()->take(2)->map(fn ($w) => mb_substr($w, 0, 1))->implode('');
            @endphp

            <div class="relative flex-none border-t border-line p-3" x-data="{ menu: false }" @click.outside="menu = false">
                <div x-show="menu" x-transition.origin.bottom.duration.150ms x-cloak
                     class="absolute inset-x-3 bottom-full z-popover mb-2 rounded-lg border border-line bg-raised p-1.5 shadow-lg">
                    <a href="{{ route('profile.edit') }}"
                       class="flex h-9 items-center gap-2.5 rounded-md px-2.5 text-sm text-fg hover:bg-sunken">
                        <x-icon name="user-circle" class="size-4 text-fg-muted" />
                        Profile
                    </a>

                    <div class="px-2.5 pb-1.5 pt-2">
                        <p class="kicker">Theme</p>
                        <div class="mt-1.5 grid grid-cols-3 gap-1 rounded-md bg-sunken p-0.5" role="radiogroup" aria-label="Theme">
                            @foreach (['system' => ['theme-system', 'Auto'], 'light' => ['sun', 'Light'], 'dark' => ['moon', 'Dark']] as $value => [$glyph, $label])
                                <button type="button" role="radio"
                                        @click="$store.theme.set('{{ $value }}')"
                                        :aria-checked="$store.theme.choice === '{{ $value }}'"
                                        :class="$store.theme.choice === '{{ $value }}' ? 'bg-surface text-fg shadow-xs' : 'text-fg-muted hover:text-fg'"
                                        class="flex h-7 items-center justify-center gap-1 rounded text-xs font-medium transition">
                                    <x-icon :name="$glyph" class="size-3.5" />
                                    {{ $label }}
                                </button>
                            @endforeach
                        </div>
                    </div>

                    <div class="my-1 border-t border-line"></div>

                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit"
                                class="flex h-9 w-full items-center gap-2.5 rounded-md px-2.5 text-sm text-fg hover:bg-sunken">
                            <x-icon name="logout" class="size-4 text-fg-muted" />
                            Sign out
                        </button>
                    </form>
                </div>

                <button type="button" @click="menu = !menu" :aria-expanded="menu"
                        class="flex w-full items-center gap-3 rounded-md p-1.5 text-left transition hover:bg-sunken">
                    <span class="grid size-8 flex-none place-items-center rounded-lg bg-brand-100 text-xs font-semibold text-brand-800"
                          aria-hidden="true">{{ $initials }}</span>
                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-sm font-medium text-fg">{{ $user->name }}</span>
                        <span class="block truncate text-xs text-fg-muted">
                            {{ $role instanceof \App\Enums\UserRole ? $role->label() : 'No role assigned' }}
                        </span>
                    </span>
                    <x-icon name="chevron-up-down" class="size-4 flex-none text-fg-subtle" />
                </button>
            </div>
        @endauth
    </aside>

    {{-- Content column --}}
    <div class="lg:pl-[260px] print:pl-0">

        <header class="sticky top-0 z-sticky flex h-14 print:hidden items-center gap-3 border-b border-line bg-canvas/85 px-4 backdrop-blur sm:px-6 lg:px-8">
            <button type="button" @click="navOpen = true" class="btn btn-ghost btn-icon btn-sm -ml-1.5 lg:hidden"
                    aria-label="Open navigation">
                <x-icon name="menu" class="size-5" />
            </button>

            {{-- Pages supply plain text: <x-slot name="title">Ticket queue</x-slot>.
                 The layout owns the h1; the page header below is an h2. --}}
            @php
                // Group, then the screen's menu entry when this page is below it
                // (a ticket inside the queue), then the page itself.
                $pageTitle = trim((string) ($title ?? ''));
                $trail = array_values(array_filter([
                    filled($section) ? ['label' => $section, 'url' => null] : null,
                    filled($sectionLabel) && $sectionLabel !== $pageTitle ? ['label' => $sectionLabel, 'url' => $sectionHome] : null,
                ]));
            @endphp
            <nav class="flex min-w-0 items-center gap-1.5 text-sm" aria-label="Breadcrumb">
                @foreach ($trail as $crumb)
                    @if ($crumb['url'])
                        <a href="{{ $crumb['url'] }}" class="hidden truncate text-fg-subtle transition hover:text-fg sm:inline">{{ $crumb['label'] }}</a>
                    @else
                        <span class="hidden truncate text-fg-subtle sm:inline">{{ $crumb['label'] }}</span>
                    @endif
                    <x-icon name="chevron-right" class="hidden size-3.5 flex-none text-fg-subtle sm:block" />
                @endforeach
                <h1 class="truncate font-medium text-fg">{{ $pageTitle }}</h1>
            </nav>

            <div class="ml-auto flex items-center gap-1">
                <button type="button" @click="$dispatch('open-command-menu')" class="btn btn-ghost btn-icon btn-sm lg:hidden"
                        aria-label="Search or jump to">
                    <x-icon name="search" class="size-[18px]" />
                </button>

                {{-- Notifications: a preview of the latest, and the full list one click on. --}}
                <div class="relative" x-data="{ open: false }" @click.outside="open = false" @keydown.escape="open = false">
                    <button type="button" @click="open = !open" :aria-expanded="open"
                            class="btn btn-ghost btn-icon btn-sm relative"
                            title="{{ $unreadAlarms > 0 ? $unreadAlarms.' unread' : 'Notifications' }}">
                        <x-icon name="bell" class="size-[18px]" />
                        @if ($unreadAlarms > 0)
                            <span class="absolute right-1 top-1 grid h-4 min-w-4 place-items-center rounded-full bg-red-600 px-1
                                         text-[10px] font-semibold leading-none text-white ring-2 ring-canvas">
                                {{ $unreadAlarms > 99 ? '99+' : $unreadAlarms }}
                            </span>
                            <span class="sr-only">{{ $unreadAlarms }} unread notifications</span>
                        @endif
                    </button>

                    <div x-show="open" x-transition.origin.top.right.duration.150ms x-cloak
                         class="absolute right-0 z-popover mt-2 w-[23rem] max-w-[calc(100vw-2rem)] overflow-hidden rounded-xl border border-line bg-raised shadow-lg">
                        <div class="flex items-center justify-between border-b border-line px-4 py-3">
                            <p class="text-sm font-semibold text-fg">Notifications</p>
                            <p class="text-xs text-fg-muted">{{ $unreadAlarms > 0 ? $unreadAlarms.' unread' : 'All caught up' }}</p>
                        </div>

                        @if ($recentAlarms->isEmpty())
                            <div class="px-4 py-10 text-center">
                                <x-icon name="bell" class="mx-auto size-6 text-fg-subtle" />
                                <p class="mt-2 text-sm text-fg-muted">Nothing yet. Shift reminders and alerts arrive here.</p>
                            </div>
                        @else
                            <ul class="max-h-[22rem] divide-y divide-line overflow-y-auto">
                                @foreach ($recentAlarms as $alarm)
                                    <li>
                                        <a href="{{ route('notifications.show', $alarm) }}"
                                           class="flex gap-3 px-4 py-3 transition hover:bg-sunken">
                                            <span class="mt-1.5 size-2 flex-none rounded-full {{ \App\Support\Tone::solid($alarm->type->tone()) }}"
                                                  aria-hidden="true"></span>
                                            <span class="min-w-0 flex-1">
                                                <span class="flex items-baseline gap-2">
                                                    <span class="truncate text-sm {{ $alarm->read_at ? 'text-fg-muted' : 'font-medium text-fg' }}">{{ $alarm->title }}</span>
                                                    <time class="ml-auto flex-none text-2xs text-fg-subtle">{{ $alarm->trigger_at?->diffForHumans(short: true) }}</time>
                                                </span>
                                                @if (filled($alarm->message))
                                                    <span class="mt-0.5 line-clamp-2 block text-xs text-fg-muted">{{ $alarm->message }}</span>
                                                @endif
                                            </span>
                                        </a>
                                    </li>
                                @endforeach
                            </ul>
                        @endif

                        <a href="{{ route('notifications.index') }}"
                           class="flex items-center justify-center gap-1.5 border-t border-line px-4 py-2.5 text-sm font-medium text-brand-700 hover:bg-sunken">
                            View all
                            <x-icon name="arrow-right" class="size-3.5" />
                        </a>
                    </div>
                </div>
            </div>
        </header>

        <main id="main" class="mx-auto w-full max-w-[1440px] px-4 py-6 sm:px-6 lg:px-8 lg:py-8 {{ $isAssistant ? 'pb-28 lg:pb-8' : '' }}">
            {{ $slot }}
        </main>
    </div>

    {{-- Assistant tab bar, phone only: the four screens used mid-round. --}}
    @if ($isAssistant)
        @php $tabs = $navigation->flatten(1)->where('exists', true)->take(4); @endphp
        <nav class="fixed inset-x-0 bottom-0 z-sticky print:hidden border-t border-line bg-surface/95 pb-[env(safe-area-inset-bottom)] backdrop-blur lg:hidden"
             aria-label="Primary">
            <div class="grid grid-cols-5">
                @foreach ($tabs as $tab)
                    <a href="{{ route($tab['route']) }}" @if ($tab['active']) aria-current="page" @endif
                       class="flex h-16 flex-col items-center justify-center gap-1 text-[11px] font-medium
                              {{ $tab['active'] ? 'text-brand-700' : 'text-fg-muted' }}">
                        <x-icon :name="$tab['icon']" :fill="$tab['active']" class="size-[22px]" />
                        <span class="max-w-full truncate px-1">{{ $tab['short'] ?? $tab['label'] }}</span>
                    </a>
                @endforeach
                <button type="button" @click="navOpen = true"
                        class="flex h-16 flex-col items-center justify-center gap-1 text-[11px] font-medium text-fg-muted">
                    <x-icon name="dots" class="size-[22px]" />
                    More
                </button>
            </div>
        </nav>
    @endif

    @include('layouts.partials.command-menu')
    @include('layouts.partials.flash')
</div>

{{-- Page scripts. The order is load-bearing: @vite emits a deferred module,
     so a plain script pushed here runs first and any x-data function it
     defines exists before Alpine starts. Push a module and that reverses. --}}
@stack('scripts')

</body>
</html>
