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
                       :subtitle="$position && $roomSize
                           ? 'Machine '.$position.' of '.$roomSize.' in '.$inspection->labPc->location->name
                           : $inspection->labPc->location?->label()">
            <x-slot:actions>
                <x-badge>{{ $inspection->template->label() }}</x-badge>
            </x-slot:actions>
        </x-page-header>

        {{-- A refused submission reloads at the top of the page, and the item
             that was missed can be twelve screens down on a phone. Say how
             many there are, and that they are marked, before anything else. --}}
        @php($unanswered = collect($errors->keys())->filter(fn ($key) => preg_match('/^results\.\d+\.result$/', $key))->count())
        @if ($unanswered > 0)
            <x-alert type="error" class="mb-6"
                     :title="$unanswered.' '.Str::plural('item', $unanswered).' '.($unanswered === 1 ? 'still needs' : 'still need').' an answer'">
                {{ $unanswered === 1 ? 'It is' : 'They are' }} marked in red below. Choose N/A for anything that does not apply to this machine.
            </x-alert>
        @endif

        @if ($inspection->results->isNotEmpty())
            <x-alert type="info" class="mb-6">
                You started this inspection {{ $inspection->started_at?->diffForHumans() }} and
                your answers so far have been kept.
            </x-alert>
        @endif

        {{-- Where this machine is in the room's round. --}}
        @if ($round->count() > 1)
            <x-round-plan :machines="$round" :current="$inspection->labPc" class="mb-6" />
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

                    {{-- Each group counts its own answers. Once every item in it
                         is answered with no issue, it folds to one line, so the
                         next group comes up under the thumb. A group with an
                         issue stays open, because its remark is still needed. --}}
                    <section aria-labelledby="section-{{ $categoryValue }}"
                             x-data="{
                                 total: {{ $items->count() }},
                                 answered: 0,
                                 passed: 0,
                                 issues: 0,
                                 open: true,
                                 get done() { return this.answered === this.total && this.issues === 0; },
                                 get status() {
                                     if (! this.done) return `${this.answered} of ${this.total} answered`;
                                     return this.passed === this.total
                                         ? `${this.total} of ${this.total} passed`
                                         : `${this.passed} passed, ${this.total - this.passed} not applicable`;
                                 },
                                 recount() {
                                     const checked = [...this.$el.querySelectorAll('input[type=radio]:checked')];
                                     this.answered = checked.length;
                                     this.passed = checked.filter((input) => input.value === '{{ \App\Enums\ChecklistResult::Ok->value }}').length;
                                     this.issues = checked.filter((input) => input.value === '{{ \App\Enums\ChecklistResult::Issue->value }}').length;
                                     if (this.done) this.open = false;
                                 },
                             }"
                             x-init="recount()" @change="recount()">
                        <div class="mb-3 flex items-baseline justify-between gap-3 px-1">
                            <h3 id="section-{{ $categoryValue }}" class="text-base font-semibold text-fg">{{ $category->label() }}</h3>
                            <span class="text-sm tabular-nums"
                                  :class="done ? 'font-medium text-emerald-700' : 'text-fg-subtle'"
                                  x-text="status">{{ $items->count() }} {{ Str::plural('item', $items->count()) }}</span>
                        </div>

                        <div x-show="! open" x-cloak
                             class="flex items-center justify-between gap-3 rounded-xl border border-line bg-surface px-4 py-3">
                            <span class="flex items-center gap-2 text-sm text-fg-muted">
                                <x-icon name="check" class="size-4 text-emerald-600" /> No problems found
                            </span>
                            <button type="button" class="btn btn-ghost btn-sm" @click="open = true">Change</button>
                        </div>

                        <div x-show="open" class="space-y-2.5">
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
                    {{-- With a machine still to check in this room, saving goes
                         straight on to it. A machine with an issue stops at its
                         summary first, where its ticket is raised. --}}
                    @if ($next)
                        <x-button type="submit" name="then" value="next" size="lg" class="flex-1">Save, then {{ $next->asset_tag }}</x-button>
                    @else
                        <x-button type="submit" size="lg" class="flex-1">Submit inspection</x-button>
                    @endif
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
