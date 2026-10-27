<x-app-layout>
    <x-slot name="title">Token for {{ $agent->name }}</x-slot>

    {{-- Rendered straight from the POST that made the token, so the token is
         never stored in the session. See AgentController. --}}
    <x-page-header :title="$fresh ? $agent->name.' is added' : 'New token for '.$agent->name"
                   subtitle="Copy the token now. Atlas keeps only a fingerprint of it, so it can't show it again." />

    <div class="max-w-2xl space-y-6">
        @unless ($fresh)
            <x-alert type="warning">The old token stopped working just now. Put this one on the machine the agent runs on.</x-alert>
        @endunless

        <x-card title="Token">
            <div x-data="{ copied: false }" class="flex gap-2">
                <input id="agent-token" type="text" readonly value="{{ $token }}" aria-label="Agent token"
                       class="block h-10 w-full font-mono text-sm" @focus="$el.select()">
                <button type="button" class="btn btn-secondary flex-none"
                        @click="navigator.clipboard.writeText(document.getElementById('agent-token').value).then(() => copied = true)">
                    <x-icon name="copy" class="size-4" />
                    <span x-text="copied ? 'Copied' : 'Copy'">Copy</span>
                </button>
            </div>
            <p class="mt-3 text-sm text-fg-muted">
                Anyone with this token can send readings for this agent's devices. Keep it in the agent's config file
                and nowhere else. If a machine is lost, make a new token, and this one stops working.
            </p>
        </x-card>

        <x-card title="Set up the agent" subtitle="The agent sends to this address, with the token in an Authorization header.">
            <dl class="space-y-3 text-sm">
                <div>
                    <dt class="text-fg-muted">Ingest address</dt>
                    <dd class="mt-0.5 font-mono text-fg">{{ route('agent.readings') }}</dd>
                </div>
                <div>
                    <dt class="text-fg-muted">Header</dt>
                    <dd class="mt-0.5 font-mono text-fg">Authorization: Bearer atl_…{{ $agent->token_hint }}</dd>
                </div>
            </dl>
            <p class="mt-4 text-sm text-fg-muted">
                Over anything but this machine or a network you trust, put Atlas behind HTTPS first, or the token travels in the clear.
            </p>
        </x-card>

        <div class="flex gap-2">
            <x-button :href="route('admin.agents.show', $agent)">Choose its devices</x-button>
            <x-button variant="secondary" :href="route('admin.agents.index')">All agents</x-button>
        </div>
    </div>
</x-app-layout>
