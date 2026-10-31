<x-app-layout>
    <x-slot name="title">{{ $device->code }}</x-slot>

    @php
        $details = [
            'Kind' => [$device->kind->label(), false],
            'Location' => [$device->location?->label() ?? '-', false],
            'Watched by' => [$device->agent?->name ?? 'No agent yet', false],
            'Model' => [$device->model ?? '-', false],
            'IP address' => [$device->ip_address ?? '-', true],
            'MAC address' => [$device->mac_address ?? '-', true],
            'Installed' => [$device->installed_at?->format('j M Y') ?? '-', false],
            'Last seen' => [$device->last_seen_at?->campus()->format('j M Y, H:i') ?? 'Never heard from', false],
        ];
    @endphp

    <a href="{{ route('admin.devices.index') }}"
       class="mb-4 inline-flex items-center gap-1.5 text-sm text-fg-muted hover:text-fg">
        <x-icon name="arrow-left" class="size-4" /> Network devices
    </a>

    <x-page-header :title="$device->code"
                   :subtitle="$device->name ?? $device->kind->label()">
        <x-slot:actions>
            <x-status-badge :status="$device->status" />
            @if ($device->trashed())
                <form method="POST" action="{{ route('admin.devices.restore', $device) }}">
                    @csrf
                    @method('PATCH')
                    <x-button type="submit" icon="refresh">Return to inventory</x-button>
                </form>
            @else
                <x-button variant="secondary" icon="edit" :href="route('admin.devices.edit', $device)">Edit</x-button>
            @endif
        </x-slot:actions>
    </x-page-header>

    @if ($device->trashed())
        <x-alert type="warning" class="mb-6">
            This device was retired {{ $device->deleted_at?->diffForHumans() }}. It is no longer
            watched. Its readings, tickets and history are kept.
        </x-alert>
    @endif

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-[20rem_minmax(0,1fr)]">
        <div class="space-y-6 self-start">
            <x-card title="Device">
                <dl class="divide-y divide-line text-sm">
                    @foreach ($details as $term => [$value, $mono])
                        <div class="flex items-baseline justify-between gap-4 py-2.5 first:pt-0 last:pb-0">
                            <dt class="flex-none text-fg-muted">{{ $term }}</dt>
                            <dd @class(['min-w-0 truncate text-right text-fg', 'font-mono text-[13px]' => $mono])>{{ $value }}</dd>
                        </div>
                    @endforeach
                </dl>
            </x-card>

            <x-card title="Connected to" subtitle="The device this one plugs into.">
                @if ($device->parent)
                    <a href="{{ route('admin.devices.show', $device->parent) }}" class="group flex items-center gap-3">
                        <span class="grid size-8 flex-none place-items-center rounded-lg bg-sunken text-fg-muted">
                            <x-icon :name="$device->parent->kind->icon()" class="size-4" />
                        </span>
                        <span class="min-w-0">
                            <span class="block font-mono text-sm font-medium text-fg group-hover:text-brand-700">{{ $device->parent->code }}</span>
                            <span class="block truncate text-xs text-fg-muted">{{ $device->parent->name ?? $device->parent->kind->label() }}</span>
                        </span>
                    </a>
                @else
                    <p class="text-sm text-fg-muted">Nothing above it. Set one when Atlas knows what it plugs into.</p>
                @endif
            </x-card>
        </div>

        <div class="min-w-0 space-y-6">
            <x-card title="Connected below it"
                    subtitle="When this device fails, these lose their connection with it."
                    flush>
                @if ($children->isEmpty())
                    <x-empty-state title="Nothing connected yet" icon="network"
                                   message="Set Connected to on an access point or another device to put it here." />
                @else
                    <ul class="divide-y divide-line">
                        @foreach ($children as $child)
                            <li>
                                <a href="{{ $child->kind === App\Enums\DeviceKind::AccessPoint ? route('admin.aps.show', $child) : route('admin.devices.show', $child) }}"
                                   class="group flex items-center gap-3 px-5 py-3 transition hover:bg-sunken/60">
                                    <span class="grid size-8 flex-none place-items-center rounded-lg bg-sunken text-fg-muted">
                                        <x-icon :name="$child->kind->icon()" class="size-4" />
                                    </span>
                                    <span class="min-w-0 flex-1">
                                        <span class="block font-mono text-sm font-medium text-fg group-hover:text-brand-700">{{ $child->code }}</span>
                                        <span class="block truncate text-xs text-fg-muted">{{ $child->kind->label() }} · {{ $child->location?->code }}</span>
                                    </span>
                                    <x-status-badge :status="$child->status" />
                                </a>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-card>

            @include('admin.partials.device-tickets', ['tickets' => $tickets])

            <x-card title="History" subtitle="Every change recorded against this record.">
                <x-timeline :events="$events->map(fn ($e) => [
                    'actor' => $e->user?->name ?? 'System',
                    'action' => $e->action.' this device',
                    'description' => $e->changes ? 'Changed: '.implode(', ', array_keys($e->changes)) : null,
                    'at' => $e->created_at,
                    'tone' => $e->tone(),
                ])" />
            </x-card>
        </div>
    </div>
</x-app-layout>
