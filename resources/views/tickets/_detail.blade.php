{{-- Shared by the engineer and assistant ticket pages.

     One partial rather than two nearly-identical views: what differs between
     the two sides is which ACTIONS are offered, and that is already decided by
     TicketPolicy and TicketService::nextStates(). Duplicating the layout so
     each side could show the same facts differently is how the two drift.

     Included with ['ticket', 'comments', 'nextStates']. --}}

@php
    $transitionLabels = [
        'acknowledged' => 'Acknowledge',
        'in_progress'  => 'Start work',
        'resolved'     => 'Resolve',
        'closed'       => 'Close',
        'reopened'     => 'Reopen',
    ];

    // Only offer a move this person is actually allowed to make. The service
    // says which moves are legal; the policy says which of them are theirs.
    $abilities = [
        'acknowledged' => 'acknowledge',
        'in_progress'  => 'resolve',
        'resolved'     => 'resolve',
        'closed'       => 'close',
        'reopened'     => 'reopen',
    ];

    $available = collect($nextStates)->filter(
        fn ($state) => auth()->user()->can($abilities[$state->value] ?? 'work', $ticket)
    );

    $canResolve = $available->contains(fn ($s) => $s->value === 'resolved');
@endphp

<div class="grid gap-4 lg:grid-cols-3">
    <div class="space-y-4 lg:col-span-2">

        <x-card>
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                    <p class="font-mono text-xs text-slate-500">{{ $ticket->ticket_number }}</p>
                    <h3 class="mt-0.5 text-lg font-semibold text-slate-900">{{ $ticket->title }}</h3>
                </div>
                <div class="flex flex-none items-center gap-2">
                    <x-priority-pill :priority="$ticket->priority" />
                    <x-status-badge :status="$ticket->status" />
                </div>
            </div>

            <p class="mt-3 whitespace-pre-line text-sm text-slate-700">{{ $ticket->description }}</p>

            @if ($ticket->isBreaching())
                <div class="mt-4">
                    <x-alert type="error">
                        This ticket passed its {{ $ticket->priority->label() }} target
                        {{ $ticket->due_at->diffForHumans() }} and is still open.
                    </x-alert>
                </div>
            @endif

            @if (filled($ticket->resolution_notes))
                <div class="mt-4 rounded-md bg-emerald-50 p-3 ring-1 ring-inset ring-emerald-600/20">
                    <p class="text-xs font-semibold uppercase tracking-wide text-emerald-800">How it was fixed</p>
                    <p class="mt-1 whitespace-pre-line text-sm text-emerald-900">{{ $ticket->resolution_notes }}</p>
                </div>
            @endif

            @if ($available->isNotEmpty())
                <x-slot:footer>
                    <div class="flex flex-wrap items-center gap-2">
                        @foreach ($available as $state)
                            @if ($state->value === 'resolved')
                                {{-- Resolving needs notes, so it opens a form rather
                                     than firing straight off a button. No silent
                                     closures. --}}
                                <button type="button" @click="$dispatch('open-modal', 'resolve-ticket')"
                                        class="rounded-md bg-brand-600 px-3 py-2 text-sm font-medium text-white hover:bg-brand-700">
                                    {{ $transitionLabels[$state->value] }}
                                </button>
                            @else
                                <form method="POST" action="{{ route('tickets.transition', $ticket) }}">
                                    @csrf
                                    @method('PATCH')
                                    <input type="hidden" name="status" value="{{ $state->value }}">
                                    <button type="submit"
                                            class="rounded-md px-3 py-2 text-sm font-medium
                                                   {{ $state->value === 'reopened'
                                                       ? 'text-red-700 hover:bg-red-50'
                                                       : 'bg-brand-600 text-white hover:bg-brand-700' }}">
                                        {{ $transitionLabels[$state->value] }}
                                    </button>
                                </form>
                            @endif
                        @endforeach
                    </div>
                </x-slot:footer>
            @endif
        </x-card>

        <x-card title="Activity" subtitle="Comments and everything that has happened to this ticket.">
            <x-timeline :events="$comments->map(fn ($c) => [
                'actor' => $c->author?->name ?? 'System',
                'action' => $c->is_internal ? 'added an internal note' : 'commented',
                'description' => $c->body,
                'at' => $c->created_at,
                'tone' => $c->is_internal ? 'neutral' : 'attention',
            ])" />

            <x-slot:footer>
                <form method="POST" action="{{ route('tickets.comments.store', $ticket) }}" class="space-y-2">
                    @csrf
                    <x-form.textarea name="body" label="Add a comment" rows="2" />

                    @can('commentInternally', $ticket)
                        <label class="flex items-center gap-2 text-sm text-slate-600">
                            <input type="checkbox" name="is_internal" value="1"
                                   class="rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                            Internal note — not shown to whoever reported this
                        </label>
                    @endcan

                    <div class="flex justify-end">
                        <button type="submit"
                                class="rounded-md bg-brand-600 px-3 py-2 text-sm font-medium text-white hover:bg-brand-700">Post</button>
                    </div>
                </form>
            </x-slot:footer>
        </x-card>
    </div>

    <div class="space-y-4 lg:col-span-1">
        <x-card title="Details">
            <dl class="space-y-3 text-sm">
                <div>
                    <dt class="text-slate-500">Subject</dt>
                    <dd class="mt-0.5 text-slate-900">{{ $ticket->subjectLabel() }}</dd>
                </div>
                <div>
                    <dt class="text-slate-500">Location</dt>
                    <dd class="mt-0.5 text-slate-900">{{ $ticket->location?->label() ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-slate-500">Category</dt>
                    <dd class="mt-0.5 text-slate-900">{{ $ticket->category->label() }}</dd>
                </div>
                <div>
                    <dt class="text-slate-500">Reported by</dt>
                    <dd class="mt-0.5 text-slate-900">
                        {{ $ticket->reporter?->name ?? 'Unknown' }}
                        <span class="block text-xs text-slate-500">{{ $ticket->opened_at?->format('j M Y, H:i') }}</span>
                    </dd>
                </div>
                <div>
                    <dt class="text-slate-500">Assigned to</dt>
                    <dd class="mt-0.5 text-slate-900">{{ $ticket->assignee?->name ?? 'Nobody yet' }}</dd>

                    {{-- FR-307's missing verb. Ownership used to be implicit —
                         whoever acknowledged a ticket kept it — so one picked
                         up by the wrong person could only be moved in the
                         database.

                         Hidden once closed: ownership of finished work is part
                         of its record. `$engineers` is passed in by the
                         controller, because a view does not query. --}}
                    @can('assign', \App\Models\Ticket::class)
                        @if (isset($engineers) && $ticket->status !== \App\Enums\TicketStatus::Closed)
                            <form method="POST" action="{{ route('tickets.assign', $ticket) }}" class="mt-2">
                                @csrf
                                @method('PATCH')

                                <label for="assign-{{ $ticket->id }}" class="sr-only">
                                    Reassign {{ $ticket->ticket_number }}
                                </label>

                                <select id="assign-{{ $ticket->id }}" name="assigned_to"
                                        onchange="this.form.submit()"
                                        class="w-full rounded border-slate-300 py-1 text-xs text-slate-600
                                               focus:border-brand-500 focus:ring-brand-500">
                                    <option value="">Nobody — return to the queue</option>
                                    @foreach ($engineers as $id => $name)
                                        <option value="{{ $id }}" @selected($ticket->assigned_to === $id)>{{ $name }}</option>
                                    @endforeach
                                </select>
                            </form>
                        @endif
                    @endcan
                </div>
                <div>
                    <dt class="text-slate-500">Due</dt>
                    <dd class="mt-0.5 {{ $ticket->isBreaching() ? 'font-medium text-red-700' : 'text-slate-900' }}">
                        {{ $ticket->due_at?->format('j M Y, H:i') ?? '—' }}
                        <span class="block text-xs text-slate-500">
                            {{ $ticket->priority->label() }} target is {{ $ticket->priority->slaHours() }} hours
                        </span>
                    </dd>
                </div>
                @if ($ticket->inspection_id && Route::has('assistant.inspections.show'))
                    <div>
                        <dt class="text-slate-500">Came from</dt>
                        <dd class="mt-0.5">
                            {{-- FR-207: the trail back from a ticket to the
                                 inspection that found the fault. --}}
                            <a href="{{ route('assistant.inspections.show', $ticket->inspection_id) }}"
                               class="text-brand-700 hover:underline">The inspection that found it</a>
                        </dd>
                    </div>
                @endif
            </dl>
        </x-card>
    </div>
</div>

@if ($canResolve)
    <x-modal name="resolve-ticket" title="Resolve {{ $ticket->ticket_number }}?">
        <form method="POST" action="{{ route('tickets.transition', $ticket) }}" id="resolve-form">
            @csrf
            @method('PATCH')
            <input type="hidden" name="status" value="resolved">
            <x-form.textarea name="resolution_notes" label="What did you do to fix it?" rows="4" required
                             help="Required. This is what the next person reads when the same fault comes back." />
        </form>
        <x-slot:footer>
            <button type="button" @click="$dispatch('close-modal', 'resolve-ticket')"
                    class="rounded-md bg-white px-3 py-2 text-sm font-medium text-slate-700 ring-1 ring-slate-300">Cancel</button>
            <button type="submit" form="resolve-form"
                    class="rounded-md bg-brand-600 px-3 py-2 text-sm font-medium text-white hover:bg-brand-700">Resolve</button>
        </x-slot:footer>
    </x-modal>
@endif
