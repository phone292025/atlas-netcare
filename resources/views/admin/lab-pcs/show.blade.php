<x-app-layout>
    <x-slot name="title">{{ $labPc->asset_tag }}</x-slot>

    @php
        $machine = [
            'Location' => [$labPc->location?->label() ?? '-', false],
            'Hostname' => [$labPc->hostname ?? '-', true],
            'Last inspected' => [$labPc->last_inspected_at?->campus()->format('j M Y') ?? 'Never inspected', false],
        ];
        $hardware = [
            'CPU' => $labPc->cpu ?? '-',
            'Memory' => $labPc->ram_gb ? $labPc->ram_gb.' GB' : '-',
            'Storage' => $labPc->storage ?? '-',
            'Operating system' => $labPc->os_version ?? '-',
            'Purchased' => $labPc->purchase_date
                ? $labPc->purchase_date->format('j M Y').' ('.number_format($labPc->ageInYears(), 1).' years old)'
                : '-',
        ];
    @endphp

    <a href="{{ route('admin.lab-pcs.index') }}"
       class="mb-4 inline-flex items-center gap-1.5 text-sm text-fg-muted hover:text-fg">
        <x-icon name="arrow-left" class="size-4" /> Lab PCs
    </a>

    <x-page-header :title="$labPc->asset_tag"
                   :subtitle="$labPc->location?->label() ?? 'No location'">
        <x-slot:actions>
            <x-status-badge :status="$labPc->status" />
            @if ($labPc->trashed())
                <form method="POST" action="{{ route('admin.lab-pcs.restore', $labPc) }}">
                    @csrf
                    @method('PATCH')
                    <x-button type="submit" icon="refresh">Return to service</x-button>
                </form>
            @else
                <x-button variant="secondary" icon="edit" :href="route('admin.lab-pcs.edit', $labPc)">Edit</x-button>
            @endif
        </x-slot:actions>
    </x-page-header>

    @if ($labPc->trashed())
        <x-alert type="warning" class="mb-6">
            This machine was retired {{ $labPc->deleted_at?->diffForHumans() }}. It is out of
            inspection rounds and risk scoring, and its history below is kept.
        </x-alert>
    @endif

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-[20rem_minmax(0,1fr)]">
        <div class="space-y-6">
            <x-card title="Machine">
                <dl class="divide-y divide-line text-sm">
                    @foreach ($machine as $term => [$value, $mono])
                        <div class="flex items-baseline justify-between gap-4 py-2.5 first:pt-0 last:pb-0">
                            <dt class="flex-none text-fg-muted">{{ $term }}</dt>
                            <dd @class(['min-w-0 truncate text-right text-fg', 'font-mono text-[13px]' => $mono])>{{ $value }}</dd>
                        </div>
                    @endforeach
                </dl>
            </x-card>

            <x-card title="Hardware">
                <dl class="divide-y divide-line text-sm">
                    @foreach ($hardware as $term => $value)
                        <div class="flex items-baseline justify-between gap-4 py-2.5 first:pt-0">
                            <dt class="flex-none text-fg-muted">{{ $term }}</dt>
                            <dd class="min-w-0 text-right text-fg">{{ $value }}</dd>
                        </div>
                    @endforeach
                    <div class="flex items-baseline justify-between gap-4 pt-2.5">
                        <dt class="flex-none text-fg-muted">Warranty</dt>
                        <dd class="flex items-center gap-2 text-right text-fg">
                            {{ $labPc->warranty_expiry?->format('j M Y') ?? '-' }}
                            @if ($labPc->warranty_expiry?->isPast())
                                <x-badge tone="attention">Expired</x-badge>
                            @endif
                        </dd>
                    </div>
                </dl>
            </x-card>
        </div>

        <div class="min-w-0 space-y-6">
            {{-- Risk, tickets and history: what the other modules know about
                 this machine. --}}
            <x-card title="Risk" subtitle="Predictive maintenance score, recalculated daily.">
                @if ($labPc->risk_band)
                    <x-slot:header>
                        <a href="{{ route('engineer.risk.show', ['lab-pc', $labPc->id]) }}" class="link text-sm">Why this score</a>
                    </x-slot:header>
                    <x-risk-gauge :score="(float) $labPc->risk_score" :band="$labPc->risk_band" />
                @else
                    <x-empty-state title="Not scored yet" icon="gauge"
                                   message="The daily scoring run weighs inspection history, ticket history and the machine's age. This one has not been through it yet." />
                @endif
            </x-card>

            @include('admin.partials.device-tickets', ['tickets' => $tickets])

            <x-card title="History" subtitle="Every change recorded against this record.">
                {{-- NFR-06: every significant change is attributable to a
                     person and a time. This is that trail, for one machine. --}}
                <x-timeline :events="$events->map(fn ($e) => [
                    'actor' => $e->user?->name ?? 'System',
                    'action' => $e->action.' this machine',
                    'description' => $e->changes ? 'Changed: '.implode(', ', array_keys($e->changes)) : null,
                    'at' => $e->created_at,
                    'tone' => $e->tone(),
                ])" />
            </x-card>
        </div>
    </div>
</x-app-layout>
