<x-app-layout>
    <x-slot name="title">{{ $agent->name }}</x-slot>

    @php
        $details = [
            'Last heard from' => $agent->last_seen_at?->campus()->format('j M Y, H:i:s') ?? 'Never',
            'From address' => $agent->last_address ?? '-',
            'Version' => $agent->version ?? '-',
            'Clock' => $agent->clock_offset_ms === null
                ? '-'
                : ($agent->clockIsOff() ? number_format($agent->clock_offset_ms / 1000, 1).' s out' : 'In step with Atlas'),
            'Token' => '…'.$agent->token_hint.', made '.($agent->token_issued_at?->campus()->format('j M Y') ?? '-'),
        ];
    @endphp

    <a href="{{ route('admin.agents.index') }}"
       class="mb-4 inline-flex items-center gap-1.5 text-sm text-fg-muted hover:text-fg">
        <x-icon name="arrow-left" class="size-4" /> Agents
    </a>

    <x-page-header :title="$agent->name" :subtitle="$devices->count().' '.Str::plural('device', $devices->count()).' watched'">
        <x-slot:actions>
            <x-status-badge :status="$agent->contact()" />
            @unless ($agent->trashed())
                <x-button variant="secondary" icon="refresh" @click="$dispatch('open-modal', 'confirm-rotate')">New token</x-button>
                <x-button variant="ghost" class="text-red-700 hover:bg-red-50" @click="$dispatch('open-modal', 'confirm-retire')">Retire</x-button>
            @endunless
        </x-slot:actions>
    </x-page-header>

    @if ($agent->trashed())
        <x-alert type="warning" class="mb-6">
            Retired {{ $agent->deleted_at?->diffForHumans() }}. Its token no longer works. Its readings are kept.
        </x-alert>
    @elseif ($agent->clockIsOff())
        <x-alert type="warning" class="mb-6">
            Its clock is {{ number_format(abs($agent->clock_offset_ms) / 1000, 1) }} seconds
            {{ $agent->clock_offset_ms > 0 ? 'ahead of' : 'behind' }} Atlas, so the times on its readings are off by that much.
            Turn on time sync on the machine it runs on.
        </x-alert>
    @endif

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-[20rem_minmax(0,1fr)]">
        <x-card title="Agent" class="self-start">
            <dl class="divide-y divide-line text-sm">
                @foreach ($details as $term => $value)
                    <div class="flex items-baseline justify-between gap-4 py-2.5 first:pt-0 last:pb-0">
                        <dt class="flex-none text-fg-muted">{{ $term }}</dt>
                        <dd class="min-w-0 truncate text-right text-fg">{{ $value }}</dd>
                    </div>
                @endforeach
            </dl>
        </x-card>

        <x-card title="Devices it watches"
                subtitle="It may send readings for these and no others. Choose the agent on a device's edit form."
                flush>
            @if ($devices->isEmpty())
                <x-empty-state title="Nothing to watch yet" icon="network"
                               message="Open an access point or a network device, and set Watched by to this agent." />
            @else
                <ul class="divide-y divide-line">
                    @foreach ($devices as $device)
                        <li>
                            <a href="{{ $device instanceof App\Models\AccessPoint ? route('admin.aps.show', $device) : route('admin.devices.show', $device) }}"
                               class="group flex items-center gap-3 px-5 py-3 transition hover:bg-sunken/60">
                                <span class="grid size-8 flex-none place-items-center rounded-lg bg-sunken text-fg-muted">
                                    <x-icon :name="$device->kind->icon()" class="size-4" />
                                </span>
                                <span class="min-w-0 flex-1">
                                    <span class="block font-mono text-sm font-medium text-fg group-hover:text-brand-700">{{ $device->code }}</span>
                                    <span class="block truncate text-xs text-fg-muted">{{ $device->kind->label() }} · {{ $device->location?->code }}</span>
                                </span>
                                <x-status-badge :status="$device->status" />
                            </a>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-card>
    </div>

    @unless ($agent->trashed())
        <x-modal name="confirm-rotate" :title="'Make a new token for '.$agent->name.'?'">
            <p>The current token stops working at once. Until the new one is on the machine, its readings are refused and its devices go quiet.</p>
            <x-slot:footer>
                <button type="button" @click="$dispatch('close-modal', 'confirm-rotate')" class="btn btn-secondary">Cancel</button>
                <form method="POST" action="{{ route('admin.agents.rotate', $agent) }}">
                    @csrf
                    <button type="submit" class="btn btn-primary">Make new token</button>
                </form>
            </x-slot:footer>
        </x-modal>

        <x-modal name="confirm-retire" :title="'Retire '.$agent->name.'?'">
            <p>Its token stops working and it can't be brought back. Its readings are kept. Devices it watched are left without an agent.</p>
            <x-slot:footer>
                <button type="button" @click="$dispatch('close-modal', 'confirm-retire')" class="btn btn-secondary">Cancel</button>
                <form method="POST" action="{{ route('admin.agents.destroy', $agent) }}">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="btn btn-danger">Retire agent</button>
                </form>
            </x-slot:footer>
        </x-modal>
    @endunless
</x-app-layout>
