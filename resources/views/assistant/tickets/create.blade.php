<x-app-layout>
    <x-slot name="title">Raise a ticket</x-slot>

    <div class="mx-auto max-w-2xl">
        <x-page-header title="Raise a ticket"
                       subtitle="Describe the fault. An engineer picks it up from the queue." />

        @if (filled($prefill))
            {{-- FR-207: arriving from a failed checklist item. Saying so
                 explains why the fields are already filled in, rather than
                 leaving the assistant wondering who typed it. --}}
            <x-alert type="success" class="mb-4">
                Filled in from your inspection. Check it reads properly and change anything that is wrong.
            </x-alert>
        @endif

        <form method="POST" action="{{ route('assistant.tickets.store') }}">
            @csrf

            @if (! empty($prefill['inspection_id']))
                <input type="hidden" name="inspection_id" value="{{ $prefill['inspection_id'] }}">
            @endif

            <x-card>
                <div class="space-y-4">
                    <x-form.input name="title" label="What is wrong?" required
                                  :value="$prefill['title'] ?? null"
                                  help="One line an engineer can scan in a queue." />

                    <x-form.textarea name="description" label="Describe it" rows="4" required
                                     :value="$prefill['description'] ?? null"
                                     help="What happens, and anything you already tried." />

                    <div class="grid gap-4 sm:grid-cols-2">
                        <x-form.select name="category" label="Category" required :options="$categories"
                                       :selected="$prefill['category'] ?? null" placeholder="Choose one" />

                        {{-- Priority is a suggestion when it arrives pre-filled
                             from a critical checklist item, not a decision —
                             the assistant is standing at the machine and knows
                             better than the checklist does. --}}
                        <x-form.select name="priority" label="How urgent?" required :options="$priorities"
                                       :selected="$prefill['priority'] ?? 'medium'" />
                    </div>

                    @if ($prefill['ticketable_locked'] ?? false)
                        {{-- Arrived from one specific asset's risk detail
                             (FR-311). Showing a dropdown of two hundred
                             machines here would only be a chance to pick the
                             wrong one. --}}
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <span class="block text-sm font-medium text-slate-700">Which asset?</span>
                                <p class="mt-1 rounded-md bg-slate-50 px-3 py-2 font-mono text-sm text-slate-900 ring-1 ring-slate-200">
                                    {{ $prefill['ticketable_label'] }}
                                </p>
                                <p class="mt-1 text-xs text-slate-500">
                                    Flagged by the maintenance ranking.
                                    <a href="{{ route('engineer.risk.index') }}" class="underline">Change asset</a>
                                </p>
                            </div>

                            <x-form.select name="location_id" label="Where?" :options="$locations"
                                           :selected="$prefill['location_id'] ?? null"
                                           blank="Not sure" />
                        </div>

                        <input type="hidden" name="ticketable_type" value="{{ $prefill['ticketable_type'] }}">
                        <input type="hidden" name="ticketable_id" value="{{ $prefill['ticketable_id'] }}">
                    @else
                        <div class="grid gap-4 sm:grid-cols-2">
                            <x-form.select name="ticketable_id" label="Which machine?" :options="$labPcs"
                                           :selected="$prefill['ticketable_id'] ?? null"
                                           blank="Not about one machine"
                                           help="Leave blank for a fault affecting a whole room." />

                            <x-form.select name="location_id" label="Where?" :options="$locations"
                                           :selected="$prefill['location_id'] ?? null"
                                           blank="Not sure" />
                        </div>

                        {{-- The morph type is fixed here rather than chosen. An
                             assistant only ever raises tickets about lab PCs.
                             Access points reach this form only through the
                             locked branch above, from a risk detail screen. --}}
                        <input type="hidden" name="ticketable_type" value="lab_pc">
                    @endif
                </div>

                <x-slot:footer>
                    <div class="flex justify-end gap-2">
                        <a href="{{ route('assistant.tickets.index') }}"
                           class="rounded-md bg-white px-3 py-2 text-sm font-medium text-slate-700 ring-1 ring-slate-300 hover:bg-slate-50">Cancel</a>
                        <button type="submit"
                                class="rounded-md bg-brand-600 px-3 py-2 text-sm font-medium text-white hover:bg-brand-700">Raise ticket</button>
                    </div>
                </x-slot:footer>
            </x-card>
        </form>
    </div>
</x-app-layout>
