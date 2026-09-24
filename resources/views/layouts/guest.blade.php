<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    @include('layouts.partials.head')
</head>
<body class="min-h-[100dvh]">

{{--
    The signed-out shell: sign in, forgot password, reset password.

    Two columns on a wide screen, the form on the right and a short statement
    of what the system is on the left. One column on a phone, form first.
    Pages may pass a `heading` and an `intro`.
--}}

<div class="grid min-h-[100dvh] lg:grid-cols-[minmax(0,1fr)_minmax(0,34rem)]">

    <section class="relative hidden overflow-hidden border-r border-line bg-sunken lg:flex lg:flex-col lg:justify-between lg:p-12"
             aria-hidden="true">
        <div class="pointer-events-none absolute inset-0 opacity-70"
             style="background-image: radial-gradient(rgb(var(--line-strong)) 1px, transparent 1px); background-size: 22px 22px;
                    mask-image: radial-gradient(ellipse 70% 60% at 30% 40%, black, transparent);"></div>
        <div class="pointer-events-none absolute -left-40 -top-40 size-[36rem] rounded-full"
             style="background: radial-gradient(circle, rgb(var(--c-brand-500) / 0.14), transparent 65%);"></div>

        <x-brand-mark size="md" class="relative" />

        <div class="relative max-w-md">
            <p class="text-3xl font-semibold leading-tight tracking-tight text-fg text-balance">
                The campus network, lab rounds and IT tickets, in one place.
            </p>
            <p class="mt-4 max-w-sm text-base leading-relaxed text-fg-muted">
                Assistants check machines and raise faults. Engineers watch the access points and work the queue.
                Administrators keep the roster and the records.
            </p>
        </div>

        <p class="relative text-xs text-fg-subtle">Atlas NetCare, campus IT operations</p>
    </section>

    <main class="flex flex-col px-5 py-8 sm:px-10">
        <div class="lg:hidden">
            <a href="{{ route('login') }}" class="inline-flex rounded-md">
                <x-brand-mark size="md" />
            </a>
        </div>

        <div class="mx-auto flex w-full max-w-sm flex-1 flex-col justify-center py-10">
            @isset($heading)
                <h1 class="text-2xl font-semibold tracking-tight text-fg">{{ $heading }}</h1>
            @endisset
            @isset($intro)
                <p class="mt-2 text-sm leading-relaxed text-fg-muted">{{ $intro }}</p>
            @endisset

            <div class="{{ isset($heading) || isset($intro) ? 'mt-8' : '' }}">
                {{ $slot }}
            </div>
        </div>

        {{-- Accounts are created by an administrator (FR-105). Saying so here
             stops a new assistant hunting for a sign-up link that does not exist. --}}
        <p class="mx-auto max-w-sm text-center text-xs leading-relaxed text-fg-subtle">
            Accounts are created by a system administrator.
            Contact the IT office if you cannot sign in.
        </p>
    </main>
</div>

</body>
</html>
