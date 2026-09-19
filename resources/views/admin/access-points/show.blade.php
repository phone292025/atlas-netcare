<x-app-layout>
    <x-slot name="title">{{ $accessPoint->ap_code }}</x-slot>

    <x-page-header :title="$accessPoint->ap_code"
                   :subtitle="$accessPoint->location?->label() ?? 'No location'">
        <x-slot:actions>
            @if ($accessPoint->trashed())
                <form method="POST" action="{{ route('admin.aps.restore', $accessPoint) }}">
                    @csrf
                    @method('PATCH')
                    <button type="submit"
                            class="rounded-md bg-emerald-600 px-3 py-2 text-sm font-medium text-white hover:bg-emerald-700">
                        Return to inventory
                    </button>
                </form>
            @else
                <a href="{{ route('admin.aps.edit', $accessPoint) }}"
                   class="rounded-md bg-white px-3 py-2 text-sm font-medium text-slate-700 ring-1 ring-slate-300 hover:bg-slate-50">
                    Edit
                </a>
            @endif
        </x-slot:actions>
    </x-page-header>

    @if ($accessPoint->trashed())
        <x-alert type="warning" class="mb-4">
            This access point was removed {{ $accessPoint->deleted_at?->diffForHumans() }}. It is no
            longer polled and does not appear on the dashboard. Its telemetry history is kept.
        </x-alert>
    @endif

    <div class="grid gap-4 lg:grid-cols-3">
        <div class="space-y-4 lg:col-span-1">
            <x-card title="Device">
                <dl class="space-y-3 text-sm">
                    <div>
                        <dt class="text-slate-500">Status</dt>
                        <dd class="mt-0.5"><x-status-badge :status="$accessPoint->status" /></dd>
                    </div>
                    <div>
                        <dt class="text-slate-500">Location</dt>
                        <dd class="mt-0.5 text-slate-900">{{ $accessPoint->location?->label() ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-500">Model</dt>
                        <dd class="mt-0.5 text-slate-900">{{ $accessPoint->model ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-500">MAC address</dt>
                        <dd class="mt-0.5 font-mono text-slate-900">{{ $accessPoint->mac_address }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-500">IP address</dt>
                        <dd class="mt-0.5 font-mono text-slate-900">{{ $accessPoint->ip_address ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-500">Client capacity</dt>
                        <dd class="mt-0.5 text-slate-900">{{ $accessPoint->client_capacity }} clients</dd>
                    </div>
                    <div>
                        <dt class="text-slate-500">Installed</dt>
                        <dd class="mt-0.5 text-slate-900">{{ $accessPoint->installed_at?->format('j M Y') ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-500">Last seen</dt>
                        <dd class="mt-0.5 text-slate-900">
                            {{ $accessPoint->last_seen_at?->campus()->format('j M Y, H:i') ?? 'Never polled' }}
                        </dd>
                    </div>
                </dl>
            </x-card>
        </div>

        <div class="space-y-4 lg:col-span-2">
            {{--
                Slots for the modules that come after M2, built empty and
                labelled rather than left as a gap. Whoever owns M5 fills the
                telemetry card; M7 fills the risk card. Each says which module
                it is waiting for, so a page seen in week 10 explains itself.

                @see docs/modules/M2-locations-and-assets.md — "Build them with
                     slots the other modules can fill."
            --}}
            <x-card title="Telemetry" subtitle="Signal, clients and latency over time.">
                <x-empty-state title="No readings yet"
                               message="The collector arrives with M5. Once it runs, this becomes a 24-hour chart of clients, signal and latency."
                               icon="chart" />
            </x-card>

            <x-card title="Risk" subtitle="Predictive maintenance score.">
                @if ($accessPoint->risk_band)
                    <x-risk-gauge :score="(float) $accessPoint->risk_score" :band="$accessPoint->risk_band" />
                @else
                    <x-empty-state title="Not scored yet"
                                   message="Risk scoring arrives with M7. It weighs uptime, alarm history and saturation."
                                   icon="gauge" />
                @endif
            </x-card>

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
