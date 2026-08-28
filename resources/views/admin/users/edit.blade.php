<x-app-layout>
    <x-slot name="title">Edit {{ $user->name }}</x-slot>

    <x-page-header :title="'Edit '.$user->name" subtitle="{{ $user->email }}">
        <x-slot:actions>
            <a href="{{ route('admin.users.show', $user) }}"
               class="rounded-md bg-white px-3 py-2 text-sm font-medium text-slate-700 ring-1 ring-slate-300 hover:bg-slate-50">
                View account
            </a>
        </x-slot:actions>
    </x-page-header>

    <form method="POST" action="{{ route('admin.users.update', $user) }}" class="max-w-xl">
        @csrf
        @method('PATCH')

        <x-card>
            @include('admin.users._form', ['user' => $user, 'roles' => $roles])

            @cannot('changeRole', $user)
                <x-alert type="warning" class="mt-4">
                    You cannot change your own role. With no self-registration, an
                    administrator demoting themselves could leave the system with
                    nobody able to manage it.
                </x-alert>
            @endcannot

            <x-slot:footer>
                <div class="flex justify-end gap-2">
                    <a href="{{ route('admin.users.index') }}"
                       class="rounded-md bg-white px-3 py-2 text-sm font-medium text-slate-700 ring-1 ring-slate-300 hover:bg-slate-50">
                        Cancel
                    </a>
                    <button type="submit"
                            class="rounded-md bg-brand-600 px-3 py-2 text-sm font-medium text-white hover:bg-brand-700">
                        Save changes
                    </button>
                </div>
            </x-slot:footer>
        </x-card>
    </form>
</x-app-layout>
