<x-app-layout>
    <x-slot name="title">Agents</x-slot>

    <x-page-header title="Agents"
                   subtitle="Programs inside the network that measure devices and send the readings here. Each has its own token.">
        <x-slot:actions>
            <x-button icon="plus" :href="route('admin.agents.create')">Add agent</x-button>
        </x-slot:actions>
    </x-page-header>

    <form method="GET" class="mb-4 flex items-center gap-2">
        <label class="flex h-9 items-center gap-2 rounded-md px-2 text-sm text-fg-muted hover:text-fg">
            <input type="checkbox" name="retired" value="1" @checked(request()->boolean('retired')) onchange="this.form.requestSubmit()">
            Include retired
        </label>
        <noscript><x-button type="submit" variant="secondary" size="sm">Filter</x-button></noscript>
    </form>

    <x-data-table :headers="['Agent', 'Contact', ['label' => 'Devices', 'align' => 'right'], 'Version', 'Clock', 'Token', '']">
        @foreach ($agents as $agent)
            @php($url = route('admin.agents.show', $agent))
            <tr @class(['group cursor-pointer transition-colors hover:bg-sunken/70', 'opacity-60' => $agent->trashed()])
                onclick="window.location='{{ $url }}'">
                <td class="px-4 py-3">
                    <a href="{{ $url }}" class="font-medium text-fg group-hover:text-brand-700">{{ $agent->name }}</a>
                    @if ($agent->trashed())
                        <x-badge class="ml-1">Retired</x-badge>
                    @endif
                    @if ($agent->last_address)
                        <span class="block font-mono text-xs text-fg-subtle">{{ $agent->last_address }}</span>
                    @endif
                </td>
                <td class="px-4 py-3">
                    <x-status-badge :status="$agent->contact()" />
                    @if ($agent->last_seen_at)
                        <time class="mt-1 block text-xs text-fg-subtle" datetime="{{ $agent->last_seen_at->toIso8601String() }}"
                              title="{{ $agent->last_seen_at->campus()->format('D j M Y, H:i:s') }}">{{ $agent->last_seen_at->diffForHumans() }}</time>
                    @endif
                </td>
                <td class="px-4 py-3 text-right tabular-nums text-fg-muted">{{ $agent->devices_count }}</td>
                <td class="px-4 py-3 font-mono text-xs text-fg-muted">{{ $agent->version ?? '-' }}</td>
                <td class="px-4 py-3 text-sm">
                    @if ($agent->clock_offset_ms === null)
                        <span class="text-fg-subtle">-</span>
                    @elseif ($agent->clockIsOff())
                        <span class="inline-flex items-center gap-1 font-medium text-fg">
                            <x-icon name="warning" class="size-3.5" /> {{ number_format($agent->clock_offset_ms / 1000, 1) }} s out
                        </span>
                    @else
                        <span class="text-fg-muted">In step</span>
                    @endif
                </td>
                <td class="px-4 py-3 font-mono text-xs text-fg-muted">…{{ $agent->token_hint }}</td>
                <td class="px-4 py-3 text-right">
                    <a href="{{ $url }}" onclick="event.stopPropagation()"
                       class="btn btn-ghost btn-sm opacity-0 transition group-hover:opacity-100 focus:opacity-100">Open</a>
                </td>
            </tr>
        @endforeach

        <x-slot:empty>
            <x-empty-state title="No agents yet" icon="activity"
                           message="An agent runs on a machine inside the network and sends readings for the devices it watches. Add one to get its token.">
                <x-slot:action>
                    <x-button icon="plus" :href="route('admin.agents.create')">Add the first agent</x-button>
                </x-slot:action>
            </x-empty-state>
        </x-slot:empty>
    </x-data-table>
</x-app-layout>
