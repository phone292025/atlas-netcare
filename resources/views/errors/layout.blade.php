<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title') · {{ config('app.name') }}</title>

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="font-sans antialiased bg-slate-50 text-slate-900">

{{-- The error shell: the signed-out shell's look, what happened in a
     sentence, and the one link that helps.

     Deliberately NOT layouts/app.blade.php: that layout reads the signed-in
     user for the sidebar and the notification count, and an error page must
     render even when the thing that failed is exactly that. --}}

<div class="flex min-h-screen flex-col items-center justify-center px-4 py-10 text-center">

    <x-brand-mark size="lg" />

    <p class="mt-8 font-mono text-sm text-slate-400">@yield('code')</p>
    <h1 class="mt-2 text-2xl font-semibold text-slate-900">@yield('title')</h1>
    <p class="mt-3 max-w-md text-sm text-slate-600">@yield('message')</p>

    <div class="mt-8 flex flex-wrap items-center justify-center gap-3">
        @auth
            <a href="{{ route('home') }}"
               class="rounded-md bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500">
                Go to your home screen
            </a>
        @else
            <a href="{{ route('login') }}"
               class="rounded-md bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500">
                Sign in
            </a>
        @endauth
        @hasSection('extra')
            @yield('extra')
        @endif
    </div>

    <p class="mt-10 text-xs text-slate-400">{{ config('app.name') }} · Asia Pacific University</p>
</div>

</body>
</html>
