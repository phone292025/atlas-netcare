<x-app-layout>
    <x-slot name="title">{{ $accessPoint->code }}</x-slot>

    @php
        $device = [
            'Location' => [$accessPoint->location?->label() ?? '-', false],
            'Connected to' => [$accessPoint->parent?->label() ?? 'Not recorded', false],
            'Model' => [$accessPoint->model ?? '-', false],
            'MAC address' => [$accessPoint->mac_address, true],
            'IP address' => [$accessPoint->ip_address ?? '-', true],
            'Client capacity' => [$accessPoint->client_capacity.' clients', false],
            'Installed' => [$accessPoint->installed_at?->format('j M Y') ?? '-', false],
            'Last seen' => [$accessPoint->last_seen_at?->campus()->format('j M Y, H:i') ?? 'Never polled', false],
        ];
    @endphp

    <a href="{{ route('admin.aps.index') }}"
       class="mb-4 inline-flex items-center gap-1.5 text-sm text-fg-muted hover:text-fg">
        <x-icon name="arrow-left" class="size-4" /> Access points
    </a>

    <x-page-header :title="$accessPoint->code"
                   :subtitle="$accessPoint->location?->label() ?? 'No location'">
        <x-slot:actions>
            <x-status-badge :status="$accessPoint->status" />
            @if ($accessPoint->trashed())
                <form method="POST" action="{{ route('admin.aps.restore', $accessPoint) }}">
                    @csrf
                    @method('PATCH')
                    <x-button type="submit" icon="refresh">Return to inventory</x-button>
                </form>
            @else
                <x-button variant="secondary" icon="chart-line" :href="route('engineer.aps.show', $accessPoint)">Live telemetry</x-button>
                <x-button variant="secondary" icon="edit" :href="route('admin.aps.edit', $accessPoint)">Edit</x-button>
            @endif
        </x-slot:actions>
    </x-page-header>

    @if ($accessPoint->trashed())
        <x-alert type="warning" class="mb-6">
            This access point was removed {{ $accessPoint->deleted_at?->diffForHumans() }}. It is no
            longer polled and does not appear on the dashboard. Its telemetry history is kept.
        </x-alert>
    @endif

    <div class="grid gap-6 lg:grid-cols-[20rem_minmax(0,1fr)]">
        <x-card title="Device" class="self-start">
            <dl class="divide-y divide-line text-sm">
                @foreach ($device as $term => [$value, $mono])
                    <div class="flex items-baseline justify-between gap-4 py-2.5 first:pt-0 last:pb-0">
                        <dt class="flex-none text-fg-muted">{{ $term }}</dt>
                        <dd @class(['min-w-0 truncate text-right text-fg', 'font-mono text-[13px]' => $mono])>{{ $value }}</dd>
                    </div>
                @endforeach
            </dl>
        </x-card>

        <div class="min-w-0 space-y-6">
            <x-card title="Risk" subtitle="Predictive maintenance score, recalculated daily.">
                @if ($accessPoint->risk_band)
                    <x-slot:header>
                        <a href="{{ route('engineer.risk.show', ['access-point', $accessPoint->id]) }}" class="link text-sm">Why this score</a>
                    </x-slot:header>
                    <x-risk-gauge :score="(float) $accessPoint->risk_score" :band="$accessPoint->risk_band" />
                @else
                    <x-empty-state title="Not scored yet" icon="gauge"
                                   message="The daily scoring run weighs latency, packet loss, outages and saturation. This one has not been through it yet." />
                @endif
            </x-card>

            @include('admin.partials.device-tickets', ['tickets' => $tickets])

            <x-card title="History" subtitle="Every change recorded against this record.">
                <x-timeline :events="$events->map(fn ($e) => [
                    'actor' => $e->user?->name ?? 'System',
                    'action' => $e->action.' this access point',
                    'description' => $e->changes ? 'Changed: '.implode(', ', array_keys($e->changes)) : null,
                    'at' => $e->created_at,
                    'tone' => match ($e->action) {
                        'created', 'restored' => 'healthy',
                        'deleted' => 'urgent',
                        default => 'attention',
                    },
                ])" />
            </x-card>
        </div>
    </div>
</x-app-layout>
