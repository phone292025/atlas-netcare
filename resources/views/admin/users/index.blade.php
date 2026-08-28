<x-app-layout>
    <x-slot name="title">Users</x-slot>

    <x-page-header title="Users"
                   subtitle="{{ $users->total() }} {{ Str::plural('account', $users->total()) }}. Accounts are created here — there is no self-registration.">
        <x-slot:actions>
            <a href="{{ route('admin.users.create') }}"
               class="inline-flex items-center gap-1.5 rounded-md bg-brand-600 px-3 py-2 text-sm font-medium text-white hover:bg-brand-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 focus-visible:ring-offset-2">
                <x-icon name="plus" class="h-4 w-4" />
                Add user
            </a>
        </x-slot:actions>
    </x-page-header>

    <x-data-table :headers="[
        ['label' => 'Name', 'sort' => 'name'],
        ['label' => 'Email', 'sort' => 'email'],
        ['label' => 'Role', 'sort' => 'role'],
        'Status',
        '',
    ]">
        <x-slot:filters>
            <form method="GET" class="flex flex-wrap items-end gap-2">
                <div class="w-56">
                    <x-form.input name="search" label="Search" :value="request('search')"
                                  placeholder="Name, email or TP number" />
                </div>
                <div class="w-40">
                    <x-form.select name="role" label="Role" placeholder="Any role"
                                   :options="$roles" :selected="request('role')" />
                </div>
                <div class="w-36">
                    <x-form.select name="status" label="Status" placeholder="Any"
                                   :options="['active' => 'Active', 'inactive' => 'Deactivated']"
                                   :selected="request('status')" />
                </div>
                <button type="submit"
                        class="rounded-md bg-white px-3 py-2 text-sm font-medium text-slate-700 ring-1 ring-slate-300 hover:bg-slate-50">
                    Filter
                </button>
                @if (request()->hasAny(['search', 'role', 'status']))
                    <a href="{{ route('admin.users.index') }}" class="px-2 py-2 text-sm text-slate-500 underline">Clear</a>
                @endif
            </form>
        </x-slot:filters>

        @foreach ($users as $user)
            <tr class="hover:bg-slate-50">
                <td class="px-4 py-3">
                    <a href="{{ route('admin.users.show', $user) }}" class="font-medium text-slate-900 hover:text-brand-700">
                        {{ $user->name }}
                    </a>
                    @if ($user->tp_number)
                        <span class="block text-xs text-slate-500">{{ $user->tp_number }}</span>
                    @endif
                </td>
                <td class="px-4 py-3 text-slate-600">{{ $user->email }}</td>
                <td class="px-4 py-3">
                    @if ($user->role)
                        <x-badge tone="neutral">{{ $user->role->label() }}</x-badge>
                    @else
                        <span class="text-xs text-slate-400">No role</span>
                    @endif
                </td>
                <td class="px-4 py-3">
                    <x-badge :tone="$user->is_active ? 'healthy' : 'urgent'">
                        {{ $user->is_active ? 'Active' : 'Deactivated' }}
                    </x-badge>
                </td>
                <td class="px-4 py-3 text-right">
                    <a href="{{ route('admin.users.edit', $user) }}" class="text-sm text-brand-700 hover:underline">Edit</a>
                </td>
            </tr>
        @endforeach

        <x-slot:empty>
            <x-empty-state title="No accounts match those filters"
                           message="Every person who uses Atlas NetCare is added here by an administrator."
                           icon="users">
                <x-slot:action>
                    <a href="{{ route('admin.users.create') }}"
                       class="rounded-md bg-brand-600 px-3 py-2 text-sm font-medium text-white hover:bg-brand-700">
                        Add the first user
                    </a>
                </x-slot:action>
            </x-empty-state>
        </x-slot:empty>

        <x-slot:pagination>
            <x-pagination :paginator="$users" />
        </x-slot:pagination>
    </x-data-table>
</x-app-layout>
