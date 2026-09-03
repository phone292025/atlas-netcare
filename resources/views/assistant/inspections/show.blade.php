<x-app-layout>
    <x-slot name="title">{{ $inspection->labPc->asset_tag }} inspection</x-slot>

    <div class="mx-auto max-w-2xl">
        <x-page-header :title="$inspection->labPc->asset_tag"
                       :subtitle="$inspection->labPc->location?->label()">
            <x-slot:actions>
                <x-status-badge :status="$inspection->overall_result" />
            </x-slot:actions>
        </x-page-header>

        @php($issues = $inspection->issues())

        @if ($issues->isNotEmpty())
            {{--
                The hand-off to M4 and the demo's best moment: a failed item
                already knows the machine, the location and the fault, so
                raising a ticket does not ask for any of it again.

                Live as of M4. The panel was built in M3 with the link behind a
                Route::has() guard, so wiring it up was deleting the guard —
                which is what building a hand-off ahead of the module that
                receives it is supposed to cost.

                @see docs/01-requirements.md FR-207
            --}}
            <x-card title="{{ $issues->count() }} {{ Str::plural('issue', $issues->count()) }} found"
                    subtitle="Each of these can become a ticket without retyping anything."
                    class="mb-4">
                <ul class="space-y-3">
                    @foreach ($issues as $result)
                        <li class="flex items-start justify-between gap-3 rounded-md bg-slate-50 p-3">
                            <div class="min-w-0">
                                <p class="text-sm font-medium text-slate-900">
                                    {{ $result->item?->label }}
                                    @if ($result->item?->is_critical)
                                        <x-badge tone="urgent">Critical</x-badge>
                                    @endif
                                </p>
                                @if (filled($result->remark))
                                    <p class="mt-0.5 text-sm text-slate-600">{{ $result->remark }}</p>
                                @endif
                            </div>

                            <a href="{{ route('assistant.tickets.create', [
                                   'inspection' => $inspection->id,
                                   'item' => $result->checklist_item_id,
                               ]) }}"
                               {{-- py-3, not py-2: this lands at 44px like the
                                    checklist buttons. It was 36px, measured at
                                    375px on 2026-09-04, and it is the one tap
                                    that carries a fault into a ticket. --}}
                               class="flex-none rounded-md bg-brand-600 px-3 py-3 text-sm font-medium text-white hover:bg-brand-700">
                                Raise ticket
                            </a>
                        </li>
                    @endforeach
                </ul>
            </x-card>
        @endif

        <x-card title="What was checked" :subtitle="$inspection->template->label()" class="mb-4">
            <ul class="divide-y divide-slate-100">
                @foreach ($inspection->results->sortBy(fn ($r) => $r->item?->sort_order) as $result)
                    <li class="flex items-start justify-between gap-3 py-2.5 first:pt-0 last:pb-0">
                        <div class="min-w-0">
                            <p class="text-sm text-slate-900">{{ $result->item?->label }}</p>
                            @if (filled($result->remark))
                                <p class="mt-0.5 text-xs text-slate-500">{{ $result->remark }}</p>
                            @endif
                        </div>
                        <x-status-badge :status="$result->result" class="flex-none" />
                    </li>
                @endforeach
            </ul>
        </x-card>

        <x-card title="Record">
            <dl class="space-y-3 text-sm">
                <div>
                    <dt class="text-slate-500">Inspected by</dt>
                    <dd class="mt-0.5 text-slate-900">{{ $inspection->user?->name ?? 'Unknown' }}</dd>
                </div>
                <div>
                    <dt class="text-slate-500">Completed</dt>
                    <dd class="mt-0.5 text-slate-900">
                        @if ($inspection->completed_at)
                            <time datetime="{{ $inspection->completed_at->toIso8601String() }}"
                                  title="{{ $inspection->completed_at->format('D j M Y, H:i') }}">
                                {{ $inspection->completed_at->format('j M Y, H:i') }}
                            </time>
                        @else
                            Still in progress
                        @endif
                    </dd>
                </div>
                @if (filled($inspection->notes))
                    <div>
                        <dt class="text-slate-500">Notes</dt>
                        <dd class="mt-0.5 text-slate-900">{{ $inspection->notes }}</dd>
                    </div>
                @endif
            </dl>
        </x-card>
    </div>
</x-app-layout>
