<x-app-layout>
    <x-slot name="title">Inspect {{ $inspection->labPc->asset_tag }}</x-slot>

    {{--
        THE MOST-USED SCREEN IN THE SYSTEM.

        An assistant works down a rack on a phone, standing up, one hand. NFR-05
        is tested at 375px and this is the page it is tested on. Everything here
        follows from that:

          - one column, always; no grid that collapses awkwardly in between
          - the submit bar is sticky at the bottom, within thumb reach, so
            finishing a machine never means scrolling back up
          - sections carry their own headings so the form reads as four short
            lists rather than one list of thirteen
          - <x-checklist-item> owns the tap targets and the remark box

        @see docs/01-requirements.md FR-204, FR-205, NFR-05
    --}}

    <div class="mx-auto max-w-2xl">
        <x-page-header :title="$inspection->labPc->asset_tag"
                       :subtitle="$inspection->labPc->location?->label()">
            <x-slot:actions>
                <span class="text-xs text-slate-500">{{ $inspection->template->label() }}</span>
            </x-slot:actions>
        </x-page-header>

        @if ($inspection->results->isNotEmpty())
            <x-alert type="warning" class="mb-4">
                You started this inspection {{ $inspection->started_at?->diffForHumans() }} and
                your answers so far have been kept.
            </x-alert>
        @endif

        <form method="POST" action="{{ route('assistant.inspections.update', $inspection) }}">
            @csrf
            @method('PATCH')

            @foreach ($sections as $categoryValue => $items)
                @php($category = \App\Enums\ChecklistCategory::from($categoryValue))

                <section class="mb-6">
                    <h3 class="mb-2 px-1 text-xs font-semibold uppercase tracking-wide text-slate-500">
                        {{ $category->label() }}
                    </h3>

                    <div class="space-y-2">
                        @foreach ($items as $item)
                            {{-- ->get(), not [$item->id]. A Collection's array
                                 access throws on a missing key, so on a fresh
                                 inspection — no answers saved yet, which is
                                 every inspection at the moment it starts —
                                 subscript syntax 500s the whole form. --}}
                            @php($answer = $saved->get($item->id))

                            <x-checklist-item :item="$item"
                                              :result="$answer?->result?->value"
                                              :remark="$answer?->remark"
                                              name-prefix="results[{{ $item->id }}]" />
                        @endforeach
                    </div>
                </section>
            @endforeach

            <x-card title="Anything else?" class="mb-4">
                <x-form.textarea name="notes" label="Notes for this machine" rows="3"
                                 :value="$inspection->notes"
                                 help="Optional. Something the next person should know." />
            </x-card>

            {{-- Sticky, so submitting never means scrolling back up past
                 thirteen questions on a phone. --}}
            <div class="sticky bottom-0 -mx-4 border-t border-slate-200 bg-white/95 px-4 py-3 backdrop-blur sm:mx-0 sm:rounded-lg sm:border sm:px-4">
                <div class="flex items-center gap-2">
                    <button type="submit"
                            class="flex-1 rounded-md bg-brand-600 px-4 py-3 text-sm font-semibold text-white hover:bg-brand-700">
                        Submit inspection
                    </button>
                    <button type="button" @click="$dispatch('open-modal', 'abandon-inspection')"
                            class="rounded-md px-3 py-3 text-sm font-medium text-slate-600 hover:bg-slate-100">
                        Abandon
                    </button>
                </div>
            </div>
        </form>
    </div>

    <x-modal name="abandon-inspection" title="Abandon this inspection?">
        <p>Nothing will be recorded against {{ $inspection->labPc->asset_tag }} and the
           machine will still show as not inspected. You can start again at any time.</p>
        <x-slot:footer>
            <button type="button" @click="$dispatch('close-modal', 'abandon-inspection')"
                    class="rounded-md bg-white px-3 py-2 text-sm font-medium text-slate-700 ring-1 ring-slate-300">Keep going</button>
            <form method="POST" action="{{ route('assistant.inspections.destroy', $inspection) }}">
                @csrf
                @method('DELETE')
                <button type="submit"
                        class="rounded-md bg-red-600 px-3 py-2 text-sm font-medium text-white hover:bg-red-700">Abandon</button>
            </form>
        </x-slot:footer>
    </x-modal>
</x-app-layout>
