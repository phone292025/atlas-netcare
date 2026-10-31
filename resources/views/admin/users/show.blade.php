<x-app-layout>
    <x-slot name="title">{{ $user->name }}</x-slot>

    <a href="{{ route('admin.users.index') }}"
       class="mb-4 inline-flex items-center gap-1.5 text-sm text-fg-muted hover:text-fg">
        <x-icon name="arrow-left" class="size-4" /> Users
    </a>

    {{-- Who this is, at a glance, with the two things an administrator comes
         here to do. --}}
    <section class="mb-6 flex flex-col gap-5 rounded-xl border border-line bg-surface p-5 shadow-xs sm:flex-row sm:items-center sm:p-6">
        <x-avatar :name="$user->name" size="xl" />
        <div class="min-w-0 flex-1">
            <div class="flex flex-wrap items-center gap-2">
                <h2 class="text-2xl font-semibold tracking-tight text-fg">{{ $user->name }}</h2>
                <span class="inline-flex items-center gap-1.5 rounded-md px-2 py-0.5 text-xs font-medium ring-1 ring-inset
                             {{ \App\Support\Tone::badge($user->is_active ? 'healthy' : 'neutral') }}">
                    <span class="size-1.5 rounded-full {{ $user->is_active ? 'bg-emerald-500' : 'bg-slate-400' }}"></span>
                    {{ $user->is_active ? 'Active' : 'Deactivated' }}
                </span>
            </div>
            <p class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-fg-muted">
                <span class="inline-flex items-center gap-1.5"><x-icon name="envelope" class="size-4 text-fg-subtle" />{{ $user->email }}</span>
                <span class="inline-flex items-center gap-1.5"><x-icon name="user-circle" class="size-4 text-fg-subtle" />{{ $user->role?->label() ?? 'No role assigned' }}</span>
            </p>
        </div>
        <div class="flex flex-wrap gap-2">
            <x-button variant="secondary" icon="edit" :href="route('admin.users.edit', $user)">Edit</x-button>

            @can('deactivate', $user)
                <x-button :variant="$user->is_active ? 'danger' : 'primary'"
                          @click="$dispatch('open-modal', 'confirm-toggle-active')">
                    {{ $user->is_active ? 'Deactivate' : 'Reactivate' }}
                </x-button>
            @endcan
        </div>
    </section>

    @if ($user->must_change_password)
        <x-alert type="warning" class="mb-6">
            This account must choose a new password the next time it signs in.
        </x-alert>
    @endif

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-[20rem_minmax(0,1fr)]">
        <x-card title="Account" class="self-start">
            <dl class="divide-y divide-line text-sm">
                @foreach (array_filter([
                    'Role' => $user->role?->label() ?? 'No role assigned',
                    'TP number' => $user->tp_number,
                    'Phone' => $user->phone,
                    'Created' => $user->created_at?->campus()->format('j M Y'),
                ], 'filled') as $term => $value)
                    <div class="flex items-baseline justify-between gap-4 py-2.5 first:pt-0 last:pb-0">
                        <dt class="flex-none text-fg-muted">{{ $term }}</dt>
                        <dd @class(['min-w-0 truncate text-right text-fg', 'font-mono text-[13px]' => $term === 'TP number'])>{{ $value }}</dd>
                    </div>
                @endforeach
            </dl>
        </x-card>

        <x-card title="History" subtitle="Everything recorded against this account, newest first.">
            {{-- NFR-06: every significant change is attributable to a person
                 and a time. This is that trail, for one account. --}}
            <x-timeline :events="$events->map(fn ($e) => [
                'actor' => $e->user?->name ?? 'System',
                'action' => $e->action.' this account',
                'description' => $e->changes ? 'Changed: '.implode(', ', array_keys($e->changes)) : null,
                'at' => $e->created_at,
                'tone' => $e->tone(),
            ])" />
        </x-card>
    </div>

    @can('deactivate', $user)
        <x-modal name="confirm-toggle-active"
                 :title="$user->is_active ? 'Deactivate this account?' : 'Reactivate this account?'">
            @if ($user->is_active)
                <p>{{ $user->name }} will be signed out and cannot sign in again.
                   Their inspections, tickets and audit history are kept. This is
                   not a deletion.</p>
            @else
                <p>{{ $user->name }} will be able to sign in again with their existing password.</p>
            @endif

            <x-slot:footer>
                <x-button variant="secondary" @click="$dispatch('close-modal', 'confirm-toggle-active')">Cancel</x-button>
                <form method="POST" action="{{ route('admin.users.toggle-active', $user) }}">
                    @csrf
                    @method('PATCH')
                    <x-button type="submit" :variant="$user->is_active ? 'danger' : 'primary'">
                        {{ $user->is_active ? 'Deactivate' : 'Reactivate' }}
                    </x-button>
                </form>
            </x-slot:footer>
        </x-modal>
    @endcan
</x-app-layout>
