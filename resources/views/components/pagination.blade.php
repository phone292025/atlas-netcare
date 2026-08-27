@props(['paginator'])

{{--
    Pagination for every index page.

    Written by hand rather than using $paginator->links() so it matches the rest
    of the component library — Laravel's stock Tailwind view uses its own greys,
    ring widths and focus styles, and one screen that looks subtly different
    from the others is exactly the inconsistency the UI conventions exist to
    prevent.

    Usage:  <x-pagination :paginator="$tickets" />

    THE CONTROLLER MUST CALL ->withQueryString() on the paginator. Without it,
    paging away from page 1 silently drops the filters and the <x-data-table>
    sort the user just chose, and the list appears to reset itself:

        $tickets = Ticket::query()->...->paginate(25)->withQueryString();

    That belongs in the controller, not here: a component that mutated the
    paginator it was handed would be changing its caller's object as a side
    effect of rendering.

    Renders nothing at all when there is only one page, so an index screen with
    six rows does not carry a dead control.

    @see docs/08-ui-conventions.md — Blade components
--}}

@php
    $hasPages = $paginator instanceof \Illuminate\Contracts\Pagination\Paginator && $paginator->hasPages();

    // simplePaginate() gives no total and no last page, so the numbered window
    // is only available for the length-aware kind.
    $isLengthAware = $paginator instanceof \Illuminate\Contracts\Pagination\LengthAwarePaginator;

    $pages = [];

    if ($hasPages && $isLengthAware) {
        $current = $paginator->currentPage();
        $last = $paginator->lastPage();

        // First, last, and one page either side of where we are. Anything else
        // is a row of numbers nobody reads.
        $window = collect([1, $current - 1, $current, $current + 1, $last])
            ->filter(fn ($page) => $page >= 1 && $page <= $last)
            ->unique()
            ->sort()
            ->values();

        $previous = null;

        foreach ($window as $page) {
            // A jump of more than one page becomes an ellipsis.
            if ($previous !== null && $page - $previous > 1) {
                $pages[] = null;
            }

            $pages[] = $page;
            $previous = $page;
        }
    }

    $linkClasses = 'inline-flex items-center gap-1 rounded-md px-2.5 py-1.5 text-sm font-medium
                    text-slate-600 hover:bg-slate-100 hover:text-slate-900
                    focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500';

    $disabledClasses = 'inline-flex items-center gap-1 rounded-md px-2.5 py-1.5 text-sm font-medium
                        text-slate-300 cursor-default';
@endphp

@if ($hasPages)
    <nav {{ $attributes->merge(['class' => 'flex items-center justify-between gap-4']) }}
         role="navigation"
         aria-label="Pagination">

        @if ($isLengthAware)
            <p class="hidden sm:block text-sm text-slate-500 tabular-nums">
                Showing <span class="font-medium text-slate-700">{{ $paginator->firstItem() }}</span>
                to <span class="font-medium text-slate-700">{{ $paginator->lastItem() }}</span>
                of <span class="font-medium text-slate-700">{{ $paginator->total() }}</span>
            </p>
        @else
            <span class="hidden sm:block"></span>
        @endif

        <div class="flex items-center gap-1">
            @if ($paginator->onFirstPage())
                <span class="{{ $disabledClasses }}" aria-disabled="true">
                    <x-icon name="chevron-left" class="w-4 h-4" />
                    <span class="max-sm:sr-only">Previous</span>
                </span>
            @else
                <a href="{{ $paginator->previousPageUrl() }}" rel="prev" class="{{ $linkClasses }}">
                    <x-icon name="chevron-left" class="w-4 h-4" />
                    <span class="max-sm:sr-only">Previous</span>
                </a>
            @endif

            @foreach ($pages as $page)
                @if ($page === null)
                    <span class="px-1 text-sm text-slate-400" aria-hidden="true">&hellip;</span>
                @elseif ($page === $paginator->currentPage())
                    <span aria-current="page"
                          class="inline-flex items-center rounded-md bg-brand-600 px-3 py-1.5
                                 text-sm font-medium text-white tabular-nums">
                        {{ $page }}
                    </span>
                @else
                    <a href="{{ $paginator->url($page) }}"
                       class="{{ $linkClasses }} tabular-nums"
                       aria-label="Go to page {{ $page }}">
                        {{ $page }}
                    </a>
                @endif
            @endforeach

            @if ($paginator->hasMorePages())
                <a href="{{ $paginator->nextPageUrl() }}" rel="next" class="{{ $linkClasses }}">
                    <span class="max-sm:sr-only">Next</span>
                    <x-icon name="chevron-right" class="w-4 h-4" />
                </a>
            @else
                <span class="{{ $disabledClasses }}" aria-disabled="true">
                    <span class="max-sm:sr-only">Next</span>
                    <x-icon name="chevron-right" class="w-4 h-4" />
                </span>
            @endif
        </div>
    </nav>
@endif
