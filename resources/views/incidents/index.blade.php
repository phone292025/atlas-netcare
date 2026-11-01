<x-app-layout>
    <x-slot name="title">Incidents</x-slot>

    @php
        $role = request()->user()->role->value;
        $tabs = [
            ['label' => 'Active', 'url' => route($role.'.incidents.index'), 'count' => $counts['active'], 'active' => $view === 'active', 'tone' => $counts['open'] > 0 ? 'urgent' : null],
            ['label' => 'Resolved', 'url' => route($role.'.incidents.index', ['view' => 'resolved']), 'count' => $counts['resolved'], 'active' => $view === 'resolved'],
        ];
    @endphp

    <x-page-header title="Incidents"
                   :subtitle="$counts['open'].' waiting for somebody, '.($counts['active'] - $counts['open']).' acknowledged. Opened from readings, and resolved when the device recovers.'" />

    <x-tab-nav :tabs="$tabs" label="Incident views" class="mb-4" />

    <form method="GET" class="mb-4 flex flex-wrap items-center gap-2">
        @if ($view === 'resolved')
            <input type="hidden" name="view" value="resolved">
        @endif
        <select name="rule" aria-label="Kind" onchange="this.form.requestSubmit()" class="h-9 w-auto py-0 pl-3 pr-8 text-sm">
            <option value="">Every kind</option>
            @foreach ($rules as $value => $label)
                <option value="{{ $value }}" @selected(request('rule') === $value)>{{ $label }}</option>
            @endforeach
        </select>
    </form>

    @if ($incidents->isEmpty())
        <div class="rounded-xl border border-line bg-surface">
            <x-empty-state :title="$view === 'resolved' ? 'Nothing resolved yet' : 'Nothing wrong right now'" icon="siren"
                           message="A device that stops answering, degrades or keeps changing status opens an incident here, and so does an agent that stops reporting." />
        </div>
    @else
        <ul class="space-y-2">
            @foreach ($incidents as $incident)
                <li>
                    <a href="{{ route($role.'.incidents.show', $incident) }}"
                       class="flex flex-col gap-2 rounded-xl border border-line bg-surface px-4 py-3 shadow-xs hover:bg-sunken/60 sm:flex-row sm:items-center sm:gap-4">
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-medium text-fg">{{ $incident->title }}</p>
                            <p class="mt-0.5 truncate text-xs text-fg-muted">
                                #{{ $incident->id }} · {{ $incident->rule->label() }}
                                @if ($incident->device?->location) · {{ $incident->device->location->label() }} @endif
                                @if ($incident->down_count > 1) · {{ $incident->down_count }} devices down @endif
                                · opened {{ $incident->opened_at->diffForHumans() }}
                            </p>
                        </div>
                        <div class="flex flex-none flex-wrap items-center gap-2 text-xs text-fg-muted">
                            @if ($incident->ticket)
                                <span class="font-mono">{{ $incident->ticket->ticket_number }}</span>
                            @endif
                            @if ($incident->acknowledgedBy && ! $incident->isResolved())
                                <span>{{ $incident->acknowledgedBy->name }}</span>
                            @endif
                            <x-status-badge :status="$incident->status" />
                        </div>
                    </a>
                </li>
            @endforeach
        </ul>

        <div class="mt-4">
            <x-pagination :paginator="$incidents" />
        </div>
    @endif
</x-app-layout>
