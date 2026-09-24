<x-app-layout>
    <x-slot name="title">Users</x-slot>

    @php
        $view = match (true) {
            request('status') === 'inactive' => 'inactive',
            request()->filled('role') => request('role'),
            default => 'all',
        };
        $keep = array_filter(request()->only(['search', 'sort', 'direction']), 'filled');
        $to = fn (array $query = []) => route('admin.users.index', [...$keep, ...$query]);
        $tabs = [
            ['label' => 'Everyone', 'url' => $to(), 'count' => $counts['all'], 'active' => $view === 'all'],
            ['label' => 'Assistants', 'url' => $to(['role' => 'assistant']), 'count' => $counts['assistant'], 'active' => $view === 'assistant'],
            ['label' => 'Engineers', 'url' => $to(['role' => 'engineer']), 'count' => $counts['engineer'], 'active' => $view === 'engineer'],
            ['label' => 'Administrators', 'url' => $to(['role' => 'admin']), 'count' => $counts['admin'], 'active' => $view === 'admin'],
            ['label' => 'Deactivated', 'url' => $to(['status' => 'inactive']), 'count' => $counts['inactive'], 'active' => $view === 'inactive'],
        ];
        $hidden = array_filter(request()->only(['role', 'status', 'sort', 'direction']), 'filled');
    @endphp

    <x-page-header title="Users"
                   subtitle="Everyone who can sign in. Accounts are created here; there is no self-registration.">
        <x-slot:actions>
            <x-button icon="plus" :href="route('admin.users.create')">Add user</x-button>
        </x-slot:actions>
    </x-page-header>

    <x-tab-nav :tabs="$tabs" label="Account views" class="mb-4" />

    <form method="GET" class="mb-4 flex flex-wrap items-center gap-2">
        @foreach ($hidden as $key => $value)
            <input type="hidden" name="{{ $key }}" value="{{ $value }}">
        @endforeach
        <label class="relative min-w-[14rem] flex-1 sm:max-w-sm">
            <span class="sr-only">Search accounts</span>
            <x-icon name="search" class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-fg-subtle" />
            <input type="search" name="search" value="{{ request('search') }}" placeholder="Name, email or TP number"
                   class="h-9 w-full pl-9 text-sm">
        </label>
        @if (request()->filled('search'))
            <x-button variant="ghost" size="sm" icon="close" :href="route('admin.users.index', $hidden)">Clear search</x-button>
        @endif
    </form>

    <x-data-table :headers="[
        ['label' => 'Name', 'sort' => 'name'],
        ['label' => 'Email', 'sort' => 'email'],
        ['label' => 'Role', 'sort' => 'role'],
        'Status',
        '',
    ]">
        @foreach ($users as $user)
            @php($url = route('admin.users.show', $user))
            <tr class="group cursor-pointer transition-colors hover:bg-sunken/70" onclick="window.location='{{ $url }}'">
                <td class="px-4 py-3">
                    <span class="flex items-center gap-3">
                        <x-avatar :name="$user->name" size="md" :class="$user->is_active ? '' : 'opacity-50'" />
                        <span class="min-w-0">
                            <a href="{{ $url }}" class="block font-medium text-fg group-hover:text-brand-700">{{ $user->name }}</a>
                            @if ($user->tp_number)
                                <span class="block font-mono text-xs text-fg-subtle">{{ $user->tp_number }}</span>
                            @endif
                        </span>
                    </span>
                </td>
                <td class="px-4 py-3 text-fg-muted">{{ $user->email }}</td>
                <td class="px-4 py-3">
                    @if ($user->role)
                        <x-badge tone="neutral">{{ $user->role->label() }}</x-badge>
                    @else
                        <span class="text-xs text-fg-subtle">No role</span>
                    @endif
                </td>
                <td class="px-4 py-3">
                    <span class="inline-flex items-center gap-1.5 text-sm {{ $user->is_active ? 'text-fg' : 'text-fg-subtle' }}">
                        <span class="size-1.5 rounded-full {{ $user->is_active ? 'bg-emerald-500' : 'bg-slate-400' }}"></span>
                        {{ $user->is_active ? 'Active' : 'Deactivated' }}
                    </span>
                </td>
                <td class="px-4 py-3 text-right">
                    <a href="{{ route('admin.users.edit', $user) }}" onclick="event.stopPropagation()"
                       class="btn btn-ghost btn-sm opacity-0 transition group-hover:opacity-100 focus:opacity-100">
                        <x-icon name="edit" class="size-3.5" /> Edit
                    </a>
                </td>
            </tr>
        @endforeach

        <x-slot:empty>
            <x-empty-state title="No accounts match" icon="users"
                           message="Every person who uses Atlas NetCare is added here by an administrator.">
                <x-slot:action>
                    <x-button icon="plus" :href="route('admin.users.create')">Add a user</x-button>
                </x-slot:action>
            </x-empty-state>
        </x-slot:empty>

        <x-slot:pagination>
            <x-pagination :paginator="$users" />
        </x-slot:pagination>
    </x-data-table>
</x-app-layout>
