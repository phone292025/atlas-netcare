<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    @include('layouts.partials.head', ['title' => trim($__env->yieldContent('title')), 'bare' => true])
</head>
<body class="min-h-[100dvh]">

{{-- The error shell: the signed-out shell's look, what happened in a
     sentence, and the one link that helps.

     Deliberately NOT layouts/app.blade.php: that layout reads the signed-in
     user for the sidebar and the notification count, and an error page must
     render even when the thing that failed is exactly that. --}}

<div class="relative flex min-h-[100dvh] flex-col items-center justify-center overflow-hidden px-4 py-10 text-center">
    <div class="pointer-events-none absolute inset-0 opacity-60" aria-hidden="true"
         style="background-image: radial-gradient(rgb(var(--line-strong)) 1px, transparent 1px); background-size: 22px 22px;
                mask-image: radial-gradient(ellipse 50% 45% at 50% 45%, black, transparent);"></div>

    <div class="relative flex flex-col items-center">
        <x-brand-mark size="lg" />

        <p class="mt-10 font-mono text-sm font-medium text-brand-700">@yield('code')</p>
        <h1 class="mt-2 text-3xl font-semibold tracking-tight text-fg text-balance">@yield('title')</h1>
        <p class="mt-3 max-w-md text-sm leading-relaxed text-fg-muted">@yield('message')</p>

        <div class="mt-8 flex flex-wrap items-center justify-center gap-3">
            @auth
                <x-button icon="home" :href="route('home')">Go to your home screen</x-button>
            @else
                <x-button icon="login" :href="route('login')">Sign in</x-button>
            @endauth
            @hasSection('extra')
                @yield('extra')
            @endif
        </div>
    </div>

    <p class="relative mt-12 text-xs text-fg-subtle">{{ config('app.name') }} · Asia Pacific University</p>
</div>

</body>
</html>
