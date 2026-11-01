<x-app-layout>
    <x-slot name="title">Incident #{{ $incident->id }}</x-slot>

    @php
        $viewer = request()->user();
        $device = $incident->device;
        $details = array_filter([
            'Kind' => $incident->rule->label(),
            'Device' => $device ? $device->code.' · '.$device->name : null,
            'Agent' => $incident->agent?->name,
            'Where' => $device?->location?->label(),
            'Behind' => $device?->parent?->code,
            'Opened' => $incident->opened_at->campus()->format('j M Y, H:i'),
            'Acknowledged' => $incident->acknowledged_at ? $incident->acknowledged_at->campus()->format('j M, H:i').' by '.($incident->acknowledgedBy?->name ?? 'a removed account') : null,
            'Resolved' => $incident->resolved_at?->campus()->format('j M Y, H:i'),
        ], fn ($value) => filled($value));
        $ticketUrl = $incident->ticket
            ? ($viewer->isAssistant() ? null : route('engineer.tickets.show', $incident->ticket))
            : null;
        $timeline = $incident->events->map(fn ($event) => [
            'actor' => $event->user?->name ?? 'Atlas',
            'action' => $event->message,
            'at' => $event->happened_at,
            'tone' => $event->tone(),
        ]);
    @endphp

    <a href="{{ route($viewer->role->value.'.incidents.index') }}" class="mb-4 inline-flex items-center gap-1.5 text-sm text-fg-muted hover:text-fg">
        <x-icon name="arrow-left" class="size-4" /> Incidents
    </a>

    <x-page-header :title="$incident->title" :subtitle="'#'.$incident->id.' · '.$incident->rule->label()">
        <x-slot:actions>
            <x-status-badge :status="$incident->status" />
            @if ($can['acknowledge'])
                @if ($incident->status === \App\Enums\IncidentStatus::Open)
                    <form method="POST" action="{{ route('incidents.acknowledge', $incident) }}">
                        @csrf
                        <x-button type="submit" icon="check">Acknowledge</x-button>
                    </form>
                @endif
            @endif
            @if ($can['resolve'])
                <x-button variant="secondary" icon="check-circle" @click="$dispatch('open-modal', 'resolve-incident')">Resolve</x-button>
            @endif
            @if ($can['raiseTicket'])
                <form method="POST" action="{{ route('incidents.ticket', $incident) }}">
                    @csrf
                    <x-button type="submit" variant="secondary" icon="ticket">Raise ticket</x-button>
                </form>
            @endif
        </x-slot:actions>
    </x-page-header>

    @if ($incident->groupedInto)
        <x-alert type="info" class="mb-6">
            Grouped into <a href="{{ $incident->groupedInto->urlFor($viewer) }}" class="font-medium underline underline-offset-2">#{{ $incident->groupedInto->id }}, {{ $incident->groupedInto->title }}</a>:
            the device above it failed too, so the one incident covers both.
        </x-alert>
    @elseif ($incident->status === \App\Enums\IncidentStatus::Open && $incident->next_escalation_at)
        <x-alert type="warning" class="mb-6">
            Nobody has acknowledged it. The next people on call are told {{ $incident->next_escalation_at->diffForHumans() }}.
        </x-alert>
    @endif

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-[22rem_minmax(0,1fr)]">
        <div class="space-y-6">
            <x-card title="Incident">
                <dl class="divide-y divide-line text-sm">
                    @foreach ($details as $term => $value)
                        <div class="flex items-baseline justify-between gap-4 py-2.5 first:pt-0 last:pb-0">
                            <dt class="flex-none text-fg-muted">{{ $term }}</dt>
                            <dd class="min-w-0 break-words text-right text-fg">{{ $value }}</dd>
                        </div>
                    @endforeach
                    <div class="flex items-baseline justify-between gap-4 py-2.5 last:pb-0">
                        <dt class="flex-none text-fg-muted">Ticket</dt>
                        <dd class="min-w-0 text-right text-fg">
                            @if ($incident->ticket && $ticketUrl)
                                <a href="{{ $ticketUrl }}" class="link font-mono">{{ $incident->ticket->ticket_number }}</a>
                            @elseif ($incident->ticket)
                                <span class="font-mono">{{ $incident->ticket->ticket_number }}</span>
                            @else
                                None
                            @endif
                        </dd>
                    </div>
                </dl>
            </x-card>

            @if ($incident->devices->count() > 1)
                <x-card title="Devices in it" :subtitle="$incident->devices->whereNull('pivot.recovered_at')->count().' still down'" flush>
                    <ul class="divide-y divide-line text-sm">
                        @foreach ($incident->devices as $member)
                            <li class="flex items-center justify-between gap-3 px-5 py-2.5">
                                <span class="min-w-0 truncate"><span class="font-mono">{{ $member->code }}</span> <span class="text-fg-muted">{{ $member->location?->code }}</span></span>
                                <span class="flex-none text-xs {{ $member->pivot->recovered_at ? 'text-emerald-700' : 'text-red-700' }}">
                                    {{ $member->pivot->recovered_at ? 'Back '.\Illuminate\Support\Carbon::parse($member->pivot->recovered_at)->campus()->format('H:i') : 'Down' }}
                                </span>
                            </li>
                        @endforeach
                    </ul>
                </x-card>
            @endif

            @if ($alerts->isNotEmpty())
                <x-card title="Who was told" flush>
                    <ul class="divide-y divide-line text-sm">
                        @foreach ($alerts as $alert)
                            <li class="flex items-center justify-between gap-3 px-5 py-2.5">
                                <span class="min-w-0 truncate">{{ $alert->user?->name }} <span class="text-fg-muted">· {{ $alert->channel->label() }}</span></span>
                                <span class="flex-none text-xs text-fg-muted">
                                    {{ $alert->sent_at ? $alert->sent_at->campus()->format('H:i') : ($alert->failed_at ? 'Failed' : 'Sending') }}
                                </span>
                            </li>
                            @if ($alert->failed_at && $alert->error)
                                <li class="px-5 pb-2.5 text-xs text-red-700 break-words">{{ $alert->error }}</li>
                            @endif
                        @endforeach
                    </ul>
                </x-card>
            @endif
        </div>

        <x-card title="What happened">
            <x-timeline :events="$timeline" />
        </x-card>
    </div>
    @if ($can['resolve'])
        <x-modal name="resolve-incident" title="Resolve this incident">
            <form method="POST" action="{{ route('incidents.resolve', $incident) }}" class="space-y-4">
                @csrf
                <x-form.textarea name="note" label="Why it can be closed" required
                                 help="A known busy hour, a link already being replaced. It goes on the timeline." />
                <div class="flex justify-end gap-2">
                    <x-button variant="ghost" type="button" @click="$dispatch('close-modal', 'resolve-incident')">Cancel</x-button>
                    <x-button type="submit">Resolve</x-button>
                </div>
            </form>
        </x-modal>
    @endif
</x-app-layout>
