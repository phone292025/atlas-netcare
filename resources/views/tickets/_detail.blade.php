{{-- Shared by the engineer and assistant ticket pages.

     One partial rather than two nearly-identical views: what differs between
     the two sides is which ACTIONS are offered, and that is already decided by
     TicketPolicy and TicketService::nextStates(). Duplicating the layout so
     each side could show the same facts differently is how the two drift.

     Included with ['ticket', 'comments', 'nextStates', 'back', 'backLabel'],
     and `engineers` when reassigning is possible. --}}

@php
    $transitionLabels = [
        'acknowledged' => 'Acknowledge',
        'in_progress' => 'Start work',
        'resolved' => 'Resolve',
        'closed' => 'Close',
        'reopened' => 'Reopen',
    ];

    // Only offer a move this person is actually allowed to make. The service
    // says which moves are legal; the policy says which of them are theirs.
    $abilities = [
        'acknowledged' => 'acknowledge',
        'in_progress' => 'resolve',
        'resolved' => 'resolve',
        'closed' => 'close',
        'reopened' => 'reopen',
    ];

    $available = collect($nextStates)->filter(
        fn ($state) => auth()->user()->can($abilities[$state->value] ?? 'work', $ticket)
    );

    $canResolve = $available->contains(fn ($s) => $s->value === 'resolved');
    $viewer = auth()->user();
@endphp

<div class="mb-6 lg:mb-8">
    <a href="{{ $back }}" class="inline-flex items-center gap-1.5 text-sm text-fg-muted transition hover:text-fg sm:hidden">
        <x-icon name="arrow-left" class="size-4" />
        {{ $backLabel }}
    </a>

    <div class="mt-4 flex flex-col gap-5 sm:mt-0 lg:flex-row lg:items-start lg:justify-between">
        <div class="min-w-0">
            <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
                <span class="font-mono text-xs text-fg-muted">{{ $ticket->ticket_number }}</span>
                <x-status-badge :status="$ticket->status" />
                <x-priority-pill :priority="$ticket->priority" />
            </div>
            <h2 class="mt-2 text-2xl font-semibold tracking-tight text-fg text-balance sm:text-[26px]">{{ $ticket->title }}</h2>
            <p class="mt-1.5 text-sm text-fg-muted">
                Raised by {{ $ticket->reporter?->name ?? 'Unknown' }}
                <time datetime="{{ $ticket->opened_at?->toIso8601String() }}" title="{{ $ticket->opened_at?->campus()->format('j M Y, H:i') }}">{{ $ticket->opened_at?->diffForHumans() }}</time>
                @if ($ticket->location)
                    in {{ $ticket->location->code }}
                @endif
            </p>
        </div>

        @if ($available->isNotEmpty())
            <div class="flex flex-wrap items-center gap-2 lg:flex-none">
                @foreach ($available as $state)
                    @if ($state->value === 'resolved')
                        {{-- Resolving needs notes, so it opens a form rather than
                             firing straight off a button. No silent closures. --}}
                        <x-button icon="check-circle" @click="$dispatch('open-modal', 'resolve-ticket')">
                            {{ $transitionLabels[$state->value] }}
                        </x-button>
                    @else
                        <form method="POST" action="{{ route('tickets.transition', $ticket) }}">
                            @csrf
                            @method('PATCH')
                            <input type="hidden" name="status" value="{{ $state->value }}">
                            <x-button type="submit" :variant="$state->value === 'reopened' ? 'secondary' : 'primary'"
                                      :icon="match ($state->value) { 'acknowledged' => 'check', 'in_progress' => 'wrench', 'closed' => 'archive', default => 'refresh' }">
                                {{ $transitionLabels[$state->value] }}
                            </x-button>
                        </form>
                    @endif
                @endforeach
            </div>
        @endif
    </div>
</div>

@if ($ticket->isBreaching())
    <x-alert type="error" class="mb-6">
        This ticket passed its {{ $ticket->priority->label() }} target {{ $ticket->due_at->diffForHumans() }} and is still open.
    </x-alert>
@endif

<div class="grid grid-cols-1 gap-6 lg:grid-cols-[minmax(0,1fr)_21rem] lg:gap-8">
    <div class="min-w-0 space-y-8">

        <section class="rounded-xl border border-line bg-surface p-5 shadow-xs sm:p-6">
            <h3 class="text-sm font-semibold text-fg">Description</h3>
            <p class="mt-2 max-w-prose whitespace-pre-line text-[15px] leading-relaxed text-fg">{{ $ticket->description }}</p>

            @if (filled($ticket->resolution_notes))
                <div class="mt-5 rounded-lg bg-emerald-50 p-4 ring-1 ring-inset ring-emerald-600/20">
                    <p class="flex items-center gap-2 text-sm font-semibold text-emerald-800">
                        <x-icon name="check-circle" :fill="true" class="size-4" />
                        How it was fixed
                    </p>
                    <p class="mt-1.5 whitespace-pre-line text-sm leading-relaxed text-emerald-900">{{ $ticket->resolution_notes }}</p>
                </div>
            @endif
        </section>

        <section aria-labelledby="activity-heading">
            <h3 id="activity-heading" class="text-sm font-semibold text-fg">Activity</h3>

            <ol class="relative mt-4 space-y-5 before:absolute before:bottom-4 before:left-4 before:top-4 before:w-px before:-translate-x-1/2 before:bg-line">
                @forelse ($comments as $comment)
                    @php $who = $comment->author?->name ?? 'System'; @endphp
                    @if ($comment->isRecordedAction())
                        {{-- Something the system recorded: a status change or an assignment. --}}
                        <li class="relative flex items-center gap-3">
                            <span class="grid size-8 flex-none place-items-center rounded-full border border-line bg-surface text-fg-subtle">
                                <x-icon :name="str_starts_with($comment->body, 'Moved') ? 'arrow-right' : 'user'" class="size-3.5" />
                            </span>
                            <p class="min-w-0 flex-1 text-sm text-fg-muted">
                                <span class="font-medium text-fg">{{ $who }}</span>
                                {{ \Illuminate\Support\Str::lcfirst($comment->body) }}
                            </p>
                            <time class="flex-none text-xs text-fg-subtle" datetime="{{ $comment->created_at->toIso8601String() }}"
                                  title="{{ $comment->created_at->campus()->format('j M Y, H:i') }}">{{ $comment->created_at->diffForHumans(short: true) }}</time>
                        </li>
                    @else
                        <li class="relative flex gap-3">
                            <x-avatar :name="$who" size="md" class="ring-4 ring-canvas" />
                            <div class="min-w-0 flex-1 rounded-xl border shadow-xs {{ $comment->is_internal ? 'border-amber-600/25 bg-amber-50/60' : 'border-line bg-surface' }}">
                                <div class="flex flex-wrap items-center gap-x-2 gap-y-1 px-4 pt-3 text-sm">
                                    <span class="font-medium text-fg">{{ $who }}</span>
                                    @if ($comment->is_internal)
                                        <x-badge tone="attention">Internal note</x-badge>
                                    @endif
                                    <time class="ml-auto text-xs text-fg-subtle" datetime="{{ $comment->created_at->toIso8601String() }}"
                                          title="{{ $comment->created_at->campus()->format('j M Y, H:i') }}">{{ $comment->created_at->diffForHumans() }}</time>
                                </div>
                                <p class="whitespace-pre-line px-4 pb-3 pt-1.5 text-sm leading-relaxed text-fg">{{ $comment->body }}</p>
                            </div>
                        </li>
                    @endif
                @empty
                    <li class="relative flex items-center gap-3">
                        <span class="grid size-8 flex-none place-items-center rounded-full border border-line bg-surface text-fg-subtle">
                            <x-icon name="chat" class="size-3.5" />
                        </span>
                        <p class="text-sm text-fg-muted">No comments yet.</p>
                    </li>
                @endforelse

                <li class="relative flex gap-3">
                    <x-avatar :name="$viewer->name" size="md" class="ring-4 ring-canvas" />
                    <form method="POST" action="{{ route('tickets.comments.store', $ticket) }}" class="min-w-0 flex-1">
                        @csrf
                        <div class="rounded-xl border bg-surface shadow-xs transition focus-within:border-brand-500 focus-within:ring-2 focus-within:ring-brand-500/20
                                    {{ $errors->has('body') ? 'border-red-500' : 'border-line-strong' }}">
                            <label for="comment-body" class="sr-only">Add a comment</label>
                            <textarea id="comment-body" name="body" rows="3" placeholder="Add a comment"
                                      @error('body') aria-invalid="true" aria-describedby="comment-body-error" @enderror
                                      class="block w-full resize-y rounded-t-xl border-0 bg-transparent px-4 py-3 text-sm shadow-none focus:ring-0">{{ old('body') }}</textarea>
                            <div class="flex flex-wrap items-center justify-between gap-2 border-t border-line px-3 py-2">
                                @can('commentInternally', $ticket)
                                    <label class="flex items-center gap-2 text-xs text-fg-muted">
                                        <input type="checkbox" name="is_internal" value="1" class="size-4 rounded" @checked(old('is_internal'))>
                                        Keep it internal, hidden from whoever reported this
                                    </label>
                                @else
                                    <span></span>
                                @endcan
                                <x-button type="submit" size="sm">Post</x-button>
                            </div>
                        </div>
                        @error('body')
                            <p id="comment-body-error" class="mt-1.5 text-xs font-medium text-red-700">{{ $message }}</p>
                        @enderror
                    </form>
                </li>
            </ol>
        </section>
    </div>

    <aside class="space-y-4">
        <section class="rounded-xl border border-line bg-surface shadow-xs" aria-labelledby="details-heading">
            <h3 id="details-heading" class="border-b border-line px-5 py-3.5 text-sm font-semibold text-fg">Details</h3>
            <dl class="divide-y divide-line text-sm">
                <div class="flex gap-3 px-5 py-3">
                    <dt class="w-24 flex-none text-fg-muted">Asset</dt>
                    <dd class="min-w-0 font-mono text-[13px] text-fg">{{ $ticket->subjectLabel() }}</dd>
                </div>
                <div class="flex gap-3 px-5 py-3">
                    <dt class="w-24 flex-none text-fg-muted">Location</dt>
                    <dd class="min-w-0 text-fg">{{ $ticket->location?->label() ?? 'Not given' }}</dd>
                </div>
                <div class="flex gap-3 px-5 py-3">
                    <dt class="w-24 flex-none text-fg-muted">Category</dt>
                    <dd class="min-w-0 text-fg">{{ $ticket->category->label() }}</dd>
                </div>
                <div class="flex gap-3 px-5 py-3">
                    <dt class="w-24 flex-none text-fg-muted">Reported by</dt>
                    <dd class="min-w-0">
                        <span class="flex items-center gap-2 text-fg">
                            <x-avatar :name="$ticket->reporter?->name" />
                            <span class="truncate">{{ $ticket->reporter?->name ?? 'Unknown' }}</span>
                        </span>
                        <span class="mt-1 block text-xs text-fg-muted">{{ $ticket->opened_at?->campus()->format('j M Y, H:i') }}</span>
                    </dd>
                </div>
                <div class="flex gap-3 px-5 py-3">
                    <dt class="w-24 flex-none text-fg-muted">Assignee</dt>
                    <dd class="min-w-0 flex-1">
                        <span class="flex items-center gap-2 {{ $ticket->assignee ? 'text-fg' : 'text-fg-subtle' }}">
                            <x-avatar :name="$ticket->assignee?->name" />
                            <span class="truncate">{{ $ticket->assignee?->name ?? 'Nobody yet' }}</span>
                        </span>

                        {{-- FR-307. Hidden once closed: ownership of finished work
                             is part of its record. `$engineers` comes from the
                             controller, because a view does not query. --}}
                        @can('assign', \App\Models\Ticket::class)
                            @if (isset($engineers) && $ticket->status !== \App\Enums\TicketStatus::Closed)
                                <form method="POST" action="{{ route('tickets.assign', $ticket) }}" class="mt-2">
                                    @csrf
                                    @method('PATCH')
                                    <label for="assign-{{ $ticket->id }}" class="sr-only">Reassign {{ $ticket->ticket_number }}</label>
                                    <select id="assign-{{ $ticket->id }}" name="assigned_to" onchange="this.form.submit()"
                                            class="h-8 w-full py-0 pl-2.5 text-xs">
                                        <option value="">Nobody, back to the queue</option>
                                        {{-- A deactivated holder is not in the list. Without this the
                                             select showed "Nobody" selected, and picking it changed nothing. --}}
                                        @if ($ticket->assigned_to && ! collect($engineers)->has($ticket->assigned_to))
                                            <option value="{{ $ticket->assigned_to }}" selected disabled>{{ $ticket->assignee?->name ?? 'Someone' }} (inactive)</option>
                                        @endif
                                        @foreach ($engineers as $id => $name)
                                            <option value="{{ $id }}" @selected($ticket->assigned_to === $id)>{{ $name }}</option>
                                        @endforeach
                                    </select>
                                </form>
                            @endif
                        @endcan
                    </dd>
                </div>
                <div class="flex gap-3 px-5 py-3">
                    <dt class="w-24 flex-none text-fg-muted">Due</dt>
                    <dd class="min-w-0">
                        <x-ticket-due :ticket="$ticket" />
                        <span class="mt-1 block text-xs text-fg-muted">
                            {{ $ticket->due_at?->campus()->format('j M Y, H:i') ?? 'No target' }}.
                            {{ $ticket->priority->label() }} target is {{ $ticket->priority->slaHours() }} hours.
                        </span>
                    </dd>
                </div>
                @if ($ticket->inspection_id && Route::has('assistant.inspections.show'))
                    <div class="flex gap-3 px-5 py-3">
                        <dt class="w-24 flex-none text-fg-muted">Came from</dt>
                        <dd class="min-w-0">
                            {{-- FR-207: the trail back to the inspection that found the fault. --}}
                            <a href="{{ route('assistant.inspections.show', $ticket->inspection_id) }}" class="link">The inspection that found it</a>
                        </dd>
                    </div>
                @endif
            </dl>
        </section>
    </aside>
</div>

@if ($canResolve)
    {{-- Open again when the notes were refused, or the error sat in a closed dialog. --}}
    <x-modal name="resolve-ticket" :title="'Resolve '.$ticket->ticket_number" :show="$errors->has('resolution_notes')">
        <form method="POST" action="{{ route('tickets.transition', $ticket) }}" id="resolve-form">
            @csrf
            @method('PATCH')
            <input type="hidden" name="status" value="resolved">
            <x-form.textarea name="resolution_notes" label="What did you do to fix it?" rows="4" required maxlength="4000"
                             help="The next person reads this when the same fault comes back." />
        </form>
        <x-slot:footer>
            <x-button variant="secondary" @click="$dispatch('close-modal', 'resolve-ticket')">Cancel</x-button>
            <x-button type="submit" form="resolve-form" icon="check-circle">Resolve ticket</x-button>
        </x-slot:footer>
    </x-modal>
@endif
