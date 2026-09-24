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
          - the bar counts what is still unanswered, so nobody submits and
            gets bounced back to find the one they skipped
          - sections carry their own headings so the form reads as four short
            lists rather than one list of thirteen
          - <x-checklist-item> owns the tap targets and the remark box

        @see docs/01-requirements.md FR-204, FR-205, NFR-05
    --}}

    @php($total = $sections->flatten(1)->count())

    <div class="mx-auto max-w-2xl">
        <a href="{{ route('assistant.inspections.create', ['location' => $inspection->labPc->location_id]) }}"
           class="mb-4 inline-flex items-center gap-1.5 text-sm text-fg-muted hover:text-fg">
            <x-icon name="arrow-left" class="size-4" /> {{ $inspection->labPc->location?->code ?? 'Back' }}
        </a>

        <x-page-header :title="$inspection->labPc->asset_tag"
                       :subtitle="$inspection->labPc->location?->label()">
            <x-slot:actions>
                <x-badge>{{ $inspection->template->label() }}</x-badge>
            </x-slot:actions>
        </x-page-header>

        @if ($inspection->results->isNotEmpty())
            <x-alert type="info" class="mb-6">
                You started this inspection {{ $inspection->started_at?->diffForHumans() }} and
                your answers so far have been kept.
            </x-alert>
        @endif

        <form method="POST" action="{{ route('assistant.inspections.update', $inspection) }}"
              x-data="{
                  answered: 0,
                  count() {
                      this.answered = new Set(
                          [...this.$el.querySelectorAll('input[type=radio]:checked')].map((input) => input.name)
                      ).size;
                  },
              }"
              x-init="count()" @change="count()">
            @csrf
            @method('PATCH')

            <div class="space-y-8">
                @foreach ($sections as $categoryValue => $items)
                    @php($category = \App\Enums\ChecklistCategory::from($categoryValue))

                    <section aria-labelledby="section-{{ $categoryValue }}">
                        <div class="mb-3 flex items-baseline justify-between gap-3 px-1">
                            <h3 id="section-{{ $categoryValue }}" class="kicker">{{ $category->label() }}</h3>
                            <span class="text-xs tabular-nums text-fg-subtle">{{ $items->count() }} {{ Str::plural('item', $items->count()) }}</span>
                        </div>

                        <div class="space-y-2.5">
                            @foreach ($items as $item)
                                {{-- ->get(), not [$item->id]. A Collection's array
                                     access throws on a missing key, so on a fresh
                                     inspection (no answers saved yet, which is
                                     every inspection at the moment it starts)
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

                <x-card title="Anything else?">
                    <x-form.textarea name="notes" label="Notes for this machine" rows="3"
                                     :value="$inspection->notes"
                                     help="Optional. Something the next person should know." />
                </x-card>
            </div>

            {{-- Sticky, so submitting never means scrolling back up past
                 thirteen questions on a phone. On an assistant's phone the tab
                 bar is below it, so it sits above that. --}}
            <div class="sticky bottom-[calc(4rem+env(safe-area-inset-bottom))] z-sticky -mx-4 mt-6 border-t border-line bg-surface/95 px-4 py-3 backdrop-blur
                        sm:mx-0 sm:rounded-xl sm:border sm:shadow-md lg:bottom-4">
                <div class="mb-2.5 flex items-center justify-between text-xs">
                    <span class="font-medium text-fg" x-text="answered === {{ $total }} ? 'All {{ $total }} answered' : answered + ' of {{ $total }} answered'">
                        0 of {{ $total }} answered
                    </span>
                    <span class="text-fg-subtle" x-show="answered < {{ $total }}" x-text="({{ $total }} - answered) + ' to go'"></span>
                </div>
                <div class="mb-3 h-1 overflow-hidden rounded-full bg-sunken" aria-hidden="true">
                    <div class="h-full rounded-full transition-[width] duration-300"
                         :class="answered === {{ $total }} ? 'bg-emerald-500' : 'bg-brand-500'"
                         :style="`width: ${Math.round(answered / {{ max(1, $total) }} * 100)}%`"></div>
                </div>
                <div class="flex items-center gap-2">
                    <x-button type="submit" size="lg" class="flex-1">Submit inspection</x-button>
                    <x-button variant="ghost" size="lg" @click="$dispatch('open-modal', 'abandon-inspection')">Abandon</x-button>
                </div>
            </div>
        </form>
    </div>

    <x-modal name="abandon-inspection" title="Abandon this inspection?">
        <p>Nothing will be recorded against {{ $inspection->labPc->asset_tag }} and the
           machine will still show as not inspected. You can start again at any time.</p>
        <x-slot:footer>
            <x-button variant="secondary" @click="$dispatch('close-modal', 'abandon-inspection')">Keep going</x-button>
            <form method="POST" action="{{ route('assistant.inspections.destroy', $inspection) }}">
                @csrf
                @method('DELETE')
                <x-button type="submit" variant="danger">Abandon</x-button>
            </form>
        </x-slot:footer>
    </x-modal>
</x-app-layout>
