@props(['title', 'subtitle' => null])

{{--
    The heading block at the top of a page's content area.

    The topbar in layouts/app.blade.php already shows the page name in a fixed
    strip; this is the in-content heading that carries the subtitle and the
    page's primary actions. Both exist on purpose: the topbar tells you where
    you are once you have scrolled, this tells you what you can do here.

    It renders an <h2>. The layout owns the <h1>, so a page that puts another
    <h1> here produces two top-level headings and a screen reader has no way to
    tell which one is the page.

    Usage:
        <x-page-header title="Ticket queue" subtitle="18 open, 3 breaching SLA">
            <x-slot:actions>
                <a href="..." class="...">Raise ticket</a>
            </x-slot:actions>
        </x-page-header>

    @see docs/06-ui-conventions.md — Blade components
--}}

<div {{ $attributes->merge(['class' => 'mb-6 sm:flex sm:items-start sm:justify-between sm:gap-4']) }}>
    <div class="min-w-0">
        <h2 class="text-xl font-semibold text-slate-900">{{ $title }}</h2>

        @if (filled($subtitle))
            <p class="mt-1 text-sm text-slate-500">{{ $subtitle }}</p>
        @endif
    </div>

    @isset($actions)
        {{-- Wraps rather than scrolls: an assistant on a 375px phone gets the
             buttons stacked under the title instead of pushed off-screen. --}}
        <div class="mt-4 flex flex-wrap items-center gap-2 sm:mt-0 sm:flex-none">
            {{ $actions }}
        </div>
    @endisset
</div>
