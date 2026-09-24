@props(['title', 'subtitle' => null])

{{--
    The top of a page: its name, one line on what it is for, and the page's
    actions on the right. The layout owns the <h1> in the top bar, so this is
    an <h2>.

        <x-page-header title="Ticket queue" subtitle="18 open, 3 past their target">
            <x-slot:actions>
                <x-button :href="route('assistant.tickets.create')" icon="plus">Raise ticket</x-button>
            </x-slot:actions>
        </x-page-header>
--}}

<div {{ $attributes->merge(['class' => 'mb-6 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between lg:mb-8']) }}>
    <div class="min-w-0">
        <h2 class="text-2xl font-semibold tracking-tight text-fg text-balance sm:text-[26px]">{{ $title }}</h2>
        @if (filled($subtitle))
            <p class="mt-1.5 max-w-2xl text-sm leading-relaxed text-fg-muted">{{ $subtitle }}</p>
        @endif
    </div>

    @isset($actions)
        <div class="flex flex-wrap items-center gap-2 sm:flex-none">
            {{ $actions }}
        </div>
    @endisset
</div>
