<x-app-layout>
    <x-slot name="title">Edit {{ $user->name }}</x-slot>

    <x-page-header :title="'Edit '.$user->name" :subtitle="$user->email">
        <x-slot:actions>
            <a href="{{ route('admin.users.show', $user) }}"
               class="btn btn-secondary">
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
                       class="btn btn-secondary">
                        Cancel
                    </a>
                    <button type="submit"
                            class="btn btn-primary">
                        Save changes
                    </button>
                </div>
            </x-slot:footer>
        </x-card>
    </form>
</x-app-layout>
