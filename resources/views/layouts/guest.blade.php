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

{{-- The signed-out shell. Only the login screen uses it now: password reset
     and email verification were removed, so there is nothing else a
     signed-out person can reach.

     Palette matches layouts/app.blade.php — slate, not Breeze's gray. They are
     different neutrals, and the seam between them is visible when a user signs
     in and the page changes shade. --}}

<div class="flex min-h-screen flex-col items-center justify-center px-4 py-10">

    <a href="{{ route('login') }}" class="rounded focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500">
        <x-brand-mark size="lg" />
    </a>

    <p class="mt-2 text-sm text-slate-500">Campus network monitoring and IT operations</p>

    <div class="mt-8 w-full max-w-md rounded-lg bg-white p-6 shadow-sm ring-1 ring-slate-200">
        {{ $slot }}
    </div>

    {{-- Accounts are created by an administrator (FR-105). Saying so here stops
         a new assistant hunting for a sign-up link that does not exist. --}}
    <p class="mt-6 text-center text-xs text-slate-400">
        Accounts are created by a system administrator.<br>
        Contact the IT office if you cannot sign in.
    </p>
</div>

</body>
</html>
