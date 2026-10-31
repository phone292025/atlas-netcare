<x-app-layout>
    <x-slot name="title">Raise a ticket</x-slot>

    @php
        $category = old('category', $prefill['category'] ?? null);
        $priority = old('priority', $prefill['priority'] ?? 'medium');
        $categoryIcons = ['hardware' => 'cpu', 'network' => 'wifi', 'peripheral' => 'mouse', 'software' => 'app-window', 'other' => 'dots'];
        $priorityHints = [
            'low' => 'It can wait. Nothing is stopped.',
            'medium' => 'It is getting in the way of work.',
            'critical' => 'A class or a whole room is stopped.',
        ];
    @endphp

    <div class="mx-auto max-w-2xl">
        <x-page-header title="Raise a ticket"
                       subtitle="Describe the fault. An engineer picks it up from the queue." />

        @if (filled($prefill))
            {{-- FR-207: arriving from a failed checklist item. Saying so
                 explains why the fields are already filled in, rather than
                 leaving the assistant wondering who typed it. --}}
            <x-alert type="info" class="mb-6">
                {{-- A preventive ticket from the risk ranking carries no inspection. --}}
                Filled in from {{ isset($prefill['inspection_id']) ? 'your inspection' : 'the maintenance ranking' }}.
                Check it reads properly and change anything that is wrong.
            </x-alert>
        @endif

        <form method="POST" action="{{ route('assistant.tickets.store') }}">
            @csrf

            @if (! empty($prefill['inspection_id']))
                <input type="hidden" name="inspection_id" value="{{ $prefill['inspection_id'] }}">
                @if (! empty($prefill['inspection_result_id']))
                    <input type="hidden" name="inspection_result_id" value="{{ $prefill['inspection_result_id'] }}">
                @endif
            @endif

            <x-card>
                <div class="space-y-6">
                    <x-form.input name="title" label="What is wrong?" required
                                  :value="$prefill['title'] ?? null"
                                  help="One line an engineer can scan in a queue." />

                    <x-form.textarea name="description" label="Describe it" rows="4" required
                                     :value="$prefill['description'] ?? null"
                                     help="What happens, and anything you already tried." />

                    {{-- Chips rather than a dropdown: five choices a thumb can
                         see all at once beat a list that has to be opened. --}}
                    <fieldset>
                        <legend class="text-sm font-medium text-fg">
                            Category <span class="text-red-600" aria-hidden="true">*</span><span class="sr-only">(required)</span>
                        </legend>
                        <div class="mt-2 grid grid-cols-2 gap-2 sm:grid-cols-5">
                            @foreach ($categories as $value => $label)
                                <label class="cursor-pointer">
                                    <input type="radio" name="category" value="{{ $value }}" class="peer sr-only" required
                                           @checked((string) $category === (string) $value)>
                                    <span class="flex h-11 items-center justify-center gap-2 rounded-lg border border-line-strong bg-surface px-2 text-sm text-fg-muted transition
                                                 hover:bg-sunken peer-checked:border-brand-600 peer-checked:bg-brand-50 peer-checked:font-medium peer-checked:text-brand-800
                                                 peer-focus-visible:ring-2 peer-focus-visible:ring-brand-500/40">
                                        <x-icon :name="$categoryIcons[$value] ?? 'dots'" class="size-4" />
                                        {{ $label }}
                                    </span>
                                </label>
                            @endforeach
                        </div>
                        @error('category')
                            <p class="mt-1.5 flex items-start gap-1.5 text-xs font-medium text-red-700">
                                <x-icon name="warning" :fill="true" class="mt-px size-3.5 flex-none" /> <span>{{ $message }}</span>
                            </p>
                        @enderror
                    </fieldset>

                    {{-- Priority is a suggestion when it arrives pre-filled from a
                         critical checklist item, not a decision: the assistant is
                         standing at the machine and knows better than the
                         checklist does. --}}
                    <fieldset>
                        <legend class="text-sm font-medium text-fg">
                            How urgent? <span class="text-red-600" aria-hidden="true">*</span><span class="sr-only">(required)</span>
                        </legend>
                        <div class="mt-2 grid gap-2 sm:grid-cols-3">
                            @foreach ($priorities as $value => $label)
                                @php($level = \App\Enums\TicketPriority::from($value))
                                <label class="cursor-pointer">
                                    <input type="radio" name="priority" value="{{ $value }}" class="peer sr-only" required
                                           @checked((string) $priority === (string) $value)>
                                    <span class="flex h-full flex-col items-start gap-2 rounded-lg border border-line-strong bg-surface p-3.5 transition
                                                 hover:bg-sunken peer-checked:border-brand-600 peer-checked:bg-brand-50/60 peer-checked:ring-1 peer-checked:ring-brand-600
                                                 peer-focus-visible:ring-2 peer-focus-visible:ring-brand-500/40">
                                        <x-priority-pill :priority="$level" />
                                        <span class="text-sm leading-snug text-fg">{{ $priorityHints[$value] ?? '' }}</span>
                                        <span class="mt-auto flex items-center gap-1 text-xs text-fg-subtle">
                                            <x-icon name="clock" class="size-3.5" /> Fixed within {{ $level->slaHours() }} hours
                                        </span>
                                    </span>
                                </label>
                            @endforeach
                        </div>
                        @error('priority')
                            <p class="mt-1.5 flex items-start gap-1.5 text-xs font-medium text-red-700">
                                <x-icon name="warning" :fill="true" class="mt-px size-3.5 flex-none" /> <span>{{ $message }}</span>
                            </p>
                        @enderror
                    </fieldset>

                    @if ($prefill['ticketable_locked'] ?? false)
                        {{-- Arrived from one specific asset's risk detail
                             (FR-311). Showing a dropdown of two hundred
                             machines here would only be a chance to pick the
                             wrong one. --}}
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div class="space-y-1.5">
                                <span class="block text-sm font-medium text-fg">Which asset?</span>
                                <p class="flex h-10 items-center gap-2 rounded-md border border-line bg-sunken px-3 font-mono text-sm text-fg">
                                    <x-icon name="lock" class="size-3.5 text-fg-subtle" />
                                    {{ $prefill['ticketable_label'] }}
                                </p>
                                <p class="text-xs text-fg-muted">
                                    Flagged by the maintenance ranking.
                                    <a href="{{ route('engineer.risk.index') }}" class="link">Change asset</a>
                                </p>
                            </div>

                            <x-form.select name="location_id" label="Where?" :options="$locations"
                                           :selected="$prefill['location_id'] ?? null"
                                           blank="Not sure" />
                        </div>

                        <input type="hidden" name="ticketable_type" value="{{ $prefill['ticketable_type'] }}">
                        <input type="hidden" name="ticketable_id" value="{{ $prefill['ticketable_id'] }}">
                    @else
                        {{-- The room first, then the machine in it. The selects
                             work without JavaScript and with a screen reader.
                             The plan under them is a quicker way to pick with a
                             finger, and it marks the machines that already have
                             a ticket open. Picking a machine sets its room, and
                             moving to another room clears a machine that is not
                             in it, so the two never disagree. --}}
                        <div x-data="machinePicker(@js($machines), @js($locations),
                                                   @js((string) old('ticketable_id', $prefill['ticketable_id'] ?? '')),
                                                   @js((string) old('location_id', $prefill['location_id'] ?? '')))"
                             class="space-y-4">
                            <div class="grid gap-4 sm:grid-cols-2">
                                <x-form.select name="location_id" label="Where?" :options="$locations"
                                               :selected="$prefill['location_id'] ?? null"
                                               blank="Not sure" x-model="room" />

                                <x-form.select name="ticketable_id" label="Which machine?" :options="$labPcs"
                                               :selected="$prefill['ticketable_id'] ?? null"
                                               blank="Not about one machine"
                                               help="Leave blank for a fault affecting a whole room."
                                               x-model="machine" />
                            </div>

                            <div x-show="desks.length > 0" x-cloak aria-hidden="true"
                                 class="rounded-lg border border-line-strong px-3 pb-2 pt-3">
                                <p class="mb-3 text-xs text-fg-muted">Or tap it in <span x-text="roomName"></span>.</p>
                                <div class="grid grid-cols-4 gap-2 sm:grid-cols-8">
                                    <template x-for="desk in desks" :key="desk.id">
                                        <button type="button" tabindex="-1" @click="pick(desk.id)"
                                                :title="desk.open ? desk.tag + ', open ticket ' + desk.open.number : desk.tag"
                                                class="relative flex h-12 flex-col items-center gap-1.5 rounded-md pt-1.5 transition"
                                                :class="machine === desk.id ? 'border-2 border-brand-500 bg-brand-50' : 'border border-line-strong hover:bg-sunken'">
                                            <span class="h-0.5 w-1/2 rounded-full" :class="machine === desk.id ? 'bg-brand-500' : 'bg-line-strong'"></span>
                                            <span class="font-mono text-[11px]" :class="machine === desk.id ? 'font-semibold text-brand-700' : 'text-fg-muted'" x-text="desk.tag"></span>
                                            <span x-show="desk.open" class="absolute -right-1 -top-1 size-2.5 rounded-full bg-red-500 ring-2 ring-surface"></span>
                                        </button>
                                    </template>
                                </div>
                                <div class="mt-3 flex flex-wrap gap-x-4 gap-y-1 border-t border-line pt-2 text-xs text-fg-muted">
                                    <span class="inline-flex items-center gap-1.5"><span class="h-2.5 w-3.5 rounded-sm border-2 border-brand-500"></span>This machine</span>
                                    <span class="inline-flex items-center gap-1.5"><span class="size-2 rounded-full bg-red-500"></span>Has an open ticket</span>
                                </div>
                            </div>

                            {{-- A warning, not a block: a second fault on the same
                                 machine is still a ticket of its own. --}}
                            <template x-if="chosen?.open">
                                <x-alert type="warning">
                                    <p class="font-semibold" x-text="chosen.open.count > 1
                                        ? `${chosen.tag} already has ${chosen.open.count} open tickets`
                                        : `${chosen.tag} already has an open ticket`"></p>
                                    <p>
                                        <a x-show="chosen.open.url" :href="chosen.open.url" class="font-mono font-medium underline underline-offset-2" x-text="chosen.open.number"></a><span x-show="! chosen.open.url" class="font-mono font-medium" x-text="chosen.open.number"></span><span x-show="chosen.open.title" x-text="' · ' + chosen.open.title"></span>,
                                        raised <span x-text="chosen.open.age"></span>.
                                        <span x-text="chosen.open.taken ? 'An engineer is on it.' : 'Nobody has picked it up yet.'"></span>
                                    </p>
                                    <p class="mt-1" x-text="chosen.open.url
                                        ? 'If this is the same fault, add to that ticket instead of raising another.'
                                        : 'If this is the same fault, it does not need a second ticket.'"></p>
                                </x-alert>
                            </template>
                        </div>

                        {{-- The morph type is fixed here rather than chosen. An
                             assistant only ever raises tickets about lab PCs.
                             Access points reach this form only through the
                             locked branch above, from a risk detail screen. --}}
                        <input type="hidden" name="ticketable_type" value="lab_pc">
                    @endif
                </div>

                <x-slot:footer>
                    <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                        <x-button variant="secondary" :href="route('assistant.tickets.index')">Cancel</x-button>
                        <x-button type="submit" icon="ticket">Raise ticket</x-button>
                    </div>
                </x-slot:footer>
            </x-card>
        </form>
    </div>

    @push('scripts')
        <script>
            function machinePicker(machines, rooms, machine, room) {
                const byId = Object.fromEntries(machines.map((m) => [m.id, m]));

                return {
                    machine,
                    room,

                    init() {
                        // A machine that arrives filled in, from an inspection
                        // or a failed submit, decides the room.
                        this.follow();
                        this.$watch('machine', () => this.follow());
                        this.$watch('room', (value) => {
                            if (this.machine && byId[this.machine]?.room !== value) this.machine = '';
                        });
                    },

                    follow() {
                        const chosen = byId[this.machine];
                        if (chosen?.room && chosen.room !== this.room) this.room = chosen.room;
                    },

                    get chosen() {
                        return byId[this.machine] ?? null;
                    },

                    get desks() {
                        return this.room ? machines.filter((m) => m.room === this.room) : [];
                    },

                    get roomName() {
                        return rooms[this.room] ?? '';
                    },

                    // Tapping the chosen desk again goes back to "not about
                    // one machine".
                    pick(id) {
                        this.machine = this.machine === id ? '' : id;
                    },
                };
            }
        </script>
    @endpush
</x-app-layout>
