<x-app-layout>
    <x-slot name="title">{{ $inspection->labPc->asset_tag }} inspection</x-slot>

    @php
        $issues = $inspection->issues();
        $outcome = $inspection->overall_result;
        $tone = $outcome?->tone();
        $tally = $inspection->results->countBy(fn ($r) => $r->result?->value);
        $icon = match ($tone) {
            \App\Support\Tone::HEALTHY => 'check-circle',
            \App\Support\Tone::URGENT => 'x-circle',
            default => 'warning',
        };
    @endphp

    <div class="mx-auto max-w-3xl">
        <a href="{{ route('assistant.inspections.index') }}"
           class="mb-4 inline-flex items-center gap-1.5 text-sm text-fg-muted hover:text-fg">
            <x-icon name="arrow-left" class="size-4" /> Inspections
        </a>

        <x-page-header :title="$inspection->labPc->asset_tag"
                       :subtitle="$inspection->labPc->location?->label()" />

        {{-- The verdict first, in words and colour, then what it rests on. --}}
        <section class="mb-6 rounded-xl border border-line bg-surface p-5 shadow-xs sm:p-6">
            <div class="flex items-start gap-4">
                <span class="grid size-12 flex-none place-items-center rounded-xl {{ \App\Support\Tone::banner($tone) }} ring-1 ring-inset">
                    <x-icon :name="$icon" :fill="true" class="size-6 {{ \App\Support\Tone::accent($tone) }}" />
                </span>
                <div class="min-w-0 flex-1">
                    <h3 class="text-xl font-semibold tracking-tight {{ $outcome ? \App\Support\Tone::accent($tone) : 'text-fg' }}">
                        {{ $outcome?->label() ?? 'In progress' }}
                    </h3>
                    <p class="mt-1 text-sm text-fg-muted">
                        {{ $tally->get('ok', 0) }} OK,
                        {{ $tally->get('issue', 0) }} {{ Str::plural('issue', $tally->get('issue', 0)) }},
                        {{ $tally->get('na', 0) }} not applicable.
                        {{ $inspection->template->label() }}.
                    </p>
                </div>
            </div>

            <dl class="mt-5 grid gap-4 border-t border-line pt-4 text-sm sm:grid-cols-3">
                <div>
                    <dt class="text-xs text-fg-muted">Inspected by</dt>
                    <dd class="mt-1 flex items-center gap-2 text-fg">
                        <x-avatar :name="$inspection->user?->name" />
                        {{ $inspection->user?->name ?? 'Unknown' }}
                    </dd>
                </div>
                <div>
                    <dt class="text-xs text-fg-muted">Completed</dt>
                    <dd class="mt-1 text-fg">
                        @if ($inspection->completed_at)
                            <time datetime="{{ $inspection->completed_at->toIso8601String() }}"
                                  title="{{ $inspection->completed_at->campus()->format('D j M Y, H:i') }}">
                                {{ $inspection->completed_at->campus()->format('j M Y, H:i') }}
                            </time>
                        @else
                            Still in progress
                        @endif
                    </dd>
                </div>
                <div>
                    <dt class="text-xs text-fg-muted">Machine</dt>
                    <dd class="mt-1 truncate font-mono text-fg">{{ $inspection->labPc->hostname ?? $inspection->labPc->asset_tag }}</dd>
                </div>
                @if (filled($inspection->notes))
                    <div class="sm:col-span-3">
                        <dt class="text-xs text-fg-muted">Notes</dt>
                        <dd class="mt-1 whitespace-pre-line text-fg">{{ $inspection->notes }}</dd>
                    </div>
                @endif
            </dl>
        </section>

        @if ($issues->isNotEmpty())
            {{--
                The hand-off to tickets: a failed item already knows the machine,
                the location and the fault, so raising a ticket does not ask for
                any of it again.

                @see docs/01-requirements.md FR-207
            --}}
            <x-card class="mb-6" flush
                    title="{{ $issues->count() }} {{ Str::plural('issue', $issues->count()) }} found"
                    subtitle="Each of these can become a ticket without retyping anything.">
                <ul class="divide-y divide-line">
                    @foreach ($issues as $result)
                        <li class="flex flex-col gap-3 px-5 py-4 sm:flex-row sm:items-start sm:justify-between">
                            <div class="flex min-w-0 items-start gap-3">
                                <x-icon name="warning" :fill="true" class="mt-0.5 size-[18px] flex-none text-red-600" />
                                <div class="min-w-0">
                                    <p class="flex flex-wrap items-center gap-2 text-sm font-medium text-fg">
                                        {{ $result->item?->label }}
                                        @if ($result->item?->is_critical)
                                            <x-badge tone="urgent">Critical</x-badge>
                                        @endif
                                    </p>
                                    @if (filled($result->remark))
                                        <p class="mt-1 text-sm text-fg-muted">{{ $result->remark }}</p>
                                    @endif
                                </div>
                            </div>

                            {{-- Raised already: the ticket, not a second one. --}}
                            @if ($result->ticket)
                                <div class="flex flex-none items-center gap-2 pl-[30px] sm:pl-0">
                                    @can('view', $result->ticket)
                                        <a href="{{ route(auth()->user()->isAssistant() ? 'assistant.tickets.show' : 'engineer.tickets.show', $result->ticket) }}"
                                           class="link font-mono text-sm">{{ $result->ticket->ticket_number }}</a>
                                    @else
                                        <span class="font-mono text-sm text-fg">{{ $result->ticket->ticket_number }}</span>
                                    @endcan
                                    <x-status-badge :status="$result->ticket->status" />
                                </div>
                            @else
                                {{-- 44px tall, like the checklist buttons: it is the
                                     one tap that carries a fault into a ticket. --}}
                                <x-button icon="ticket" class="min-h-[44px] flex-none sm:min-h-0"
                                          :href="route('assistant.tickets.create', [
                                              'inspection' => $inspection->id,
                                              'item' => $result->checklist_item_id,
                                          ])">
                                    Raise ticket
                                </x-button>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </x-card>
        @endif

        <x-card title="What was checked" flush>
            <ul class="divide-y divide-line">
                @foreach ($inspection->results->sortBy(fn ($r) => $r->item?->sort_order) as $result)
                    <li class="flex items-start justify-between gap-3 px-5 py-3">
                        <div class="min-w-0">
                            <p class="text-sm text-fg">{{ $result->item?->label }}</p>
                            @if (filled($result->remark))
                                <p class="mt-0.5 text-xs text-fg-muted">{{ $result->remark }}</p>
                            @endif
                        </div>
                        <x-status-badge :status="$result->result" class="flex-none" />
                    </li>
                @endforeach
            </ul>
        </x-card>
    </div>
</x-app-layout>
