<x-app-layout>
    <x-slot name="title">{{ $labPc->asset_tag }}</x-slot>

    <x-page-header :title="$labPc->asset_tag"
                   :subtitle="$labPc->location?->label() ?? 'No location'">
        <x-slot:actions>
            @if ($labPc->trashed())
                <form method="POST" action="{{ route('admin.lab-pcs.restore', $labPc) }}">
                    @csrf
                    @method('PATCH')
                    <button type="submit"
                            class="rounded-md bg-emerald-600 px-3 py-2 text-sm font-medium text-white hover:bg-emerald-700">
                        Return to service
                    </button>
                </form>
            @else
                <a href="{{ route('admin.lab-pcs.edit', $labPc) }}"
                   class="rounded-md bg-white px-3 py-2 text-sm font-medium text-slate-700 ring-1 ring-slate-300 hover:bg-slate-50">
                    Edit
                </a>
            @endif
        </x-slot:actions>
    </x-page-header>

    @if ($labPc->trashed())
        <x-alert type="warning" class="mb-4">
            This machine was retired {{ $labPc->deleted_at?->diffForHumans() }}. It is out of
            inspection rounds and risk scoring, and its history below is kept.
        </x-alert>
    @endif

    <div class="grid gap-4 lg:grid-cols-3">
        <div class="space-y-4 lg:col-span-1">
            <x-card title="Machine">
                <dl class="space-y-3 text-sm">
                    <div>
                        <dt class="text-slate-500">Status</dt>
                        <dd class="mt-0.5"><x-status-badge :status="$labPc->status" /></dd>
                    </div>
                    <div>
                        <dt class="text-slate-500">Location</dt>
                        <dd class="mt-0.5 text-slate-900">{{ $labPc->location?->label() ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-500">Hostname</dt>
                        <dd class="mt-0.5 text-slate-900">{{ $labPc->hostname ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-500">Last inspected</dt>
                        <dd class="mt-0.5 text-slate-900">
                            {{-- M3 writes last_inspected_at. Naming the module that
                                 fills a gap is more use than an em dash. --}}
                            {{ $labPc->last_inspected_at?->campus()->format('j M Y') ?? 'Never inspected' }}
                        </dd>
                    </div>
                </dl>
            </x-card>

            <x-card title="Hardware">
                <dl class="space-y-3 text-sm">
                    <div>
                        <dt class="text-slate-500">CPU</dt>
                        <dd class="mt-0.5 text-slate-900">{{ $labPc->cpu ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-500">Memory</dt>
                        <dd class="mt-0.5 text-slate-900">{{ $labPc->ram_gb ? $labPc->ram_gb.' GB' : '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-500">Storage</dt>
                        <dd class="mt-0.5 text-slate-900">{{ $labPc->storage ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-500">Operating system</dt>
                        <dd class="mt-0.5 text-slate-900">{{ $labPc->os_version ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-slate-500">Purchased</dt>
                        <dd class="mt-0.5 text-slate-900">
                            @if ($labPc->purchase_date)
                                {{ $labPc->purchase_date->format('j M Y') }}
                                <span class="text-slate-500">({{ number_format($labPc->ageInYears(), 1) }} years old)</span>
                            @else
                                —
                            @endif
                        </dd>
                    </div>
                    <div>
                        <dt class="text-slate-500">Warranty</dt>
                        <dd class="mt-0.5 text-slate-900">
                            @if ($labPc->warranty_expiry)
                                {{ $labPc->warranty_expiry->format('j M Y') }}
                                @if ($labPc->warranty_expiry->isPast())
                                    <x-badge tone="attention">Expired</x-badge>
                                @endif
                            @else
                                —
                            @endif
                        </dd>
                    </div>
                </dl>
            </x-card>
        </div>

        <div class="space-y-4 lg:col-span-2">
            {{-- Risk, inspections and tickets: what the other modules know
                 about this machine. See docs/modules/M2-locations-and-assets.md --}}
            <x-card title="Risk" subtitle="Predictive maintenance score.">
                @if ($labPc->risk_band)
                    <x-risk-gauge :score="(float) $labPc->risk_score" :band="$labPc->risk_band" />
                @else
                    <x-empty-state title="Not scored yet"
                                   message="Risk scoring arrives with M7. It weighs inspection history, ticket history and the machine's age."
                                   icon="gauge" />
                @endif
            </x-card>

            <x-card title="Tickets" subtitle="Faults raised against this machine.">
                <x-empty-state title="No tickets"
                               message="Ticketing arrives with M4. Faults found during an inspection will be listed here."
                               icon="ticket" />
            </x-card>

            <x-card title="History" subtitle="Every change recorded against this record.">
                {{-- NFR-06: every significant change is attributable to a
                     person and a time. This is that trail, for one machine. --}}
                <x-timeline :events="$events->map(fn ($e) => [
                    'actor' => $e->user?->name ?? 'System',
                    'action' => $e->action.' this machine',
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
