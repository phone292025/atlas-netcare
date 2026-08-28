<x-app-layout>
    <x-slot name="title">{{ $user->name }}</x-slot>

    <x-page-header :title="$user->name" :subtitle="$user->email">
        <x-slot:actions>
            <a href="{{ route('admin.users.edit', $user) }}"
               class="rounded-md bg-white px-3 py-2 text-sm font-medium text-slate-700 ring-1 ring-slate-300 hover:bg-slate-50">
                Edit
            </a>

            @can('deactivate', $user)
                <button type="button"
                        @click="$dispatch('open-modal', 'confirm-toggle-active')"
                        class="rounded-md px-3 py-2 text-sm font-medium text-white
                               {{ $user->is_active ? 'bg-red-600 hover:bg-red-700' : 'bg-emerald-600 hover:bg-emerald-700' }}">
                    {{ $user->is_active ? 'Deactivate' : 'Reactivate' }}
                </button>
            @endcan
        </x-slot:actions>
    </x-page-header>

    <div class="grid gap-4 lg:grid-cols-3">
        <x-card title="Account" class="lg:col-span-1">
            <dl class="space-y-3 text-sm">
                <div>
                    <dt class="text-slate-500">Role</dt>
                    <dd class="mt-0.5">
                        @if ($user->role)
                            <x-badge tone="neutral">{{ $user->role->label() }}</x-badge>
                        @else
                            <span class="text-slate-400">No role assigned</span>
                        @endif
                    </dd>
                </div>
                <div>
                    <dt class="text-slate-500">Status</dt>
                    <dd class="mt-0.5">
                        <x-badge :tone="$user->is_active ? 'healthy' : 'urgent'">
                            {{ $user->is_active ? 'Active' : 'Deactivated' }}
                        </x-badge>
                    </dd>
                </div>
                @if ($user->tp_number)
                    <div>
                        <dt class="text-slate-500">TP number</dt>
                        <dd class="mt-0.5 text-slate-900">{{ $user->tp_number }}</dd>
                    </div>
                @endif
                @if ($user->phone)
                    <div>
                        <dt class="text-slate-500">Phone</dt>
                        <dd class="mt-0.5 text-slate-900">{{ $user->phone }}</dd>
                    </div>
                @endif
                <div>
                    <dt class="text-slate-500">Created</dt>
                    <dd class="mt-0.5 text-slate-900">{{ $user->created_at?->format('j M Y') }}</dd>
                </div>
                @if ($user->must_change_password)
                    <div>
                        <dt class="text-slate-500">Password</dt>
                        <dd class="mt-0.5"><x-badge tone="attention">Must be changed at next sign-in</x-badge></dd>
                    </div>
                @endif
            </dl>
        </x-card>

        <x-card title="History" subtitle="Everything recorded against this account." class="lg:col-span-2">
            {{-- NFR-06: every significant change is attributable to a person
                 and a time. This is that trail, for one account. --}}
            <x-timeline :events="$events->map(fn ($e) => [
                'actor' => $e->user?->name ?? 'System',
                'action' => $e->action.' this account',
                'description' => $e->changes ? 'Changed: '.implode(', ', array_keys($e->changes)) : null,
                'at' => $e->created_at,
                'tone' => match ($e->action) {
                    'created', 'restored' => 'healthy',
                    'archived', 'deleted' => 'urgent',
                    default => 'attention',
                },
            ])" />
        </x-card>
    </div>

    @can('deactivate', $user)
        <x-modal name="confirm-toggle-active"
                 :title="$user->is_active ? 'Deactivate this account?' : 'Reactivate this account?'">
            @if ($user->is_active)
                <p>{{ $user->name }} will be signed out and cannot sign in again.
                   Their inspections, tickets and audit history are kept — this is
                   not a deletion.</p>
            @else
                <p>{{ $user->name }} will be able to sign in again with their existing password.</p>
            @endif

            <x-slot:footer>
                <button type="button" @click="$dispatch('close-modal', 'confirm-toggle-active')"
                        class="rounded-md bg-white px-3 py-2 text-sm font-medium text-slate-700 ring-1 ring-slate-300 hover:bg-slate-50">
                    Cancel
                </button>
                <form method="POST" action="{{ route('admin.users.toggle-active', $user) }}">
                    @csrf
                    @method('PATCH')
                    <button type="submit"
                            class="rounded-md px-3 py-2 text-sm font-medium text-white
                                   {{ $user->is_active ? 'bg-red-600 hover:bg-red-700' : 'bg-emerald-600 hover:bg-emerald-700' }}">
                        {{ $user->is_active ? 'Deactivate' : 'Reactivate' }}
                    </button>
                </form>
            </x-slot:footer>
        </x-modal>
    @endcan
</x-app-layout>
