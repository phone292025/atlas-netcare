<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ isset($title) ? $title.' · ' : '' }}{{ config('app.name') }}</title>

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="font-sans antialiased bg-slate-50 text-slate-900">

{{--
    The application shell: fixed sidebar on desktop, slide-over drawer on
    mobile, topbar across the content column.

    Assistants use this on a phone during lab rounds and engineers use it on a
    desktop, so the shell has to work properly at both ends rather than being a
    desktop layout that merely survives being squashed.

    @see docs/06-ui-conventions.md — The app shell
--}}

<div x-data="{ sidebarOpen: false }" class="min-h-screen">

    {{-- Mobile drawer backdrop --}}
    <div x-show="sidebarOpen"
         x-transition.opacity
         @click="sidebarOpen = false"
         class="fixed inset-0 z-40 bg-slate-900/40 lg:hidden"
         x-cloak></div>

    {{-- Sidebar: permanently visible on desktop, slide-over drawer on mobile.

         The translate classes are scoped to max-lg on purpose. Written without
         the variant, `-translate-x-full` and `lg:translate-x-0` both set
         --tw-translate-x and the negative utility wins the cascade, leaving the
         sidebar off-screen on desktop with a 256px empty gutter beside it.
         Below lg there is no competing rule, so the drawer animates cleanly. --}}
    <aside class="fixed inset-y-0 left-0 z-50 w-64 bg-white border-r border-slate-200
                  flex flex-col max-lg:transition-transform max-lg:duration-200"
           :class="sidebarOpen ? 'max-lg:translate-x-0' : 'max-lg:-translate-x-full'">

        <div class="flex items-center gap-2 h-16 px-5 border-b border-slate-200">
            <a href="{{ url('/') }}" class="rounded focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500">
                <x-brand-mark />
            </a>
            <button @click="sidebarOpen = false"
                    class="ml-auto p-1 rounded text-slate-400 hover:text-slate-600 lg:hidden"
                    aria-label="Close navigation">
                <x-icon name="close" />
            </button>
        </div>

        <div class="flex-1 overflow-y-auto">
            <x-sidebar />
        </div>

        @auth
            <div class="border-t border-slate-200 p-3">
                <div class="px-2 pb-2">
                    <p class="text-sm font-medium text-slate-900 truncate">{{ auth()->user()->name }}</p>
                    <p class="text-xs text-slate-500">
                        @php $role = auth()->user()->role; @endphp
                        {{ $role instanceof \App\Enums\UserRole ? $role->label() : 'No role assigned' }}
                    </p>
                </div>
                <a href="{{ route('profile.edit') }}"
                   class="flex items-center gap-3 rounded-md px-3 py-2 text-sm font-medium text-slate-600 hover:bg-slate-100 hover:text-slate-900">
                    <x-icon name="user" class="w-5 h-5 text-slate-400" />
                    Profile
                </a>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit"
                            class="w-full flex items-center gap-3 rounded-md px-3 py-2 text-sm font-medium text-slate-600 hover:bg-slate-100 hover:text-slate-900">
                        <x-icon name="logout" class="w-5 h-5 text-slate-400" />
                        Sign out
                    </button>
                </form>
            </div>
        @endauth
    </aside>

    {{-- Content column --}}
    <div class="lg:pl-64">

        <header class="sticky top-0 z-30 flex items-center gap-3 h-16 px-4 sm:px-6
                       bg-white/90 backdrop-blur border-b border-slate-200">
            <button @click="sidebarOpen = true"
                    class="p-2 -ml-2 rounded-md text-slate-500 hover:bg-slate-100 lg:hidden"
                    aria-label="Open navigation">
                <x-icon name="menu" class="w-6 h-6" />
            </button>

            {{-- The layout owns the page heading. Pages supply plain text:
                 <x-slot name="title">Ticket queue</x-slot>
                 Never nest another heading element inside this slot. --}}
            <h1 class="text-base font-semibold text-slate-900 truncate">
                {{ $title ?? '' }}
            </h1>

            <div class="ml-auto flex items-center gap-1">
                {{-- $unreadAlarms comes from App\View\Components\AppLayout. --}}
                <a href="{{ route('notifications.index') }}"
                   class="relative p-2 rounded-md text-slate-500 hover:bg-slate-100 hover:text-slate-700
                          focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500"
                   title="{{ $unreadAlarms > 0 ? $unreadAlarms.' unread' : 'Notifications' }}">
                    <x-icon name="bell" class="w-5 h-5" />

                    @if ($unreadAlarms > 0)
                        <span class="absolute -top-0.5 -right-0.5 grid min-w-[1.15rem] place-items-center
                                     rounded-full bg-red-600 px-1 text-[0.65rem] font-semibold leading-4 text-white">
                            {{ $unreadAlarms > 99 ? '99+' : $unreadAlarms }}
                        </span>
                        <span class="sr-only">{{ $unreadAlarms }} unread notifications</span>
                    @endif
                </a>
            </div>
        </header>

        <main class="px-4 sm:px-6 py-6">
            {{-- Flash messages. Four session keys, one component, so seven module
                 owners do not each hand-roll a banner and pick their own green.
                 'status' is Breeze's key; the other three are ours.

                     return redirect()->route('...')->with('success', 'Ticket resolved.');  --}}
            @foreach (['status' => 'success', 'success' => 'success', 'warning' => 'warning', 'error' => 'error'] as $key => $type)
                @if (session()->has($key))
                    <x-alert :type="$type" class="mb-4" dismissible>{{ session($key) }}</x-alert>
                @endif
            @endforeach

            {{ $slot }}
        </main>
    </div>
</div>

{{-- Page-specific scripts.

     THE ORDER HERE IS LOAD-BEARING. @vite emits type="module", which is
     deferred and therefore runs AFTER the document is parsed — so a plain
     <script> pushed to this stack executes first, and any x-data function it
     defines exists by the time Alpine.start() runs.

     Push a module, or defer this script, and that reverses: Alpine initialises
     first, cannot find the function, and the element renders as blank markup
     with nothing in the console to explain it.

     Use it sparingly. An Alpine component more than one page needs belongs in
     resources/js/app.js instead.

         @push('scripts') <script>function apDashboard() { ... }</script> @endpush --}}
@stack('scripts')

</body>
</html>
