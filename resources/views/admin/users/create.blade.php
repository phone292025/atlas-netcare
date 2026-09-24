<x-app-layout>
    <x-slot name="title">Add user</x-slot>

    <x-page-header title="Add user"
                   subtitle="Atlas NetCare has no self-registration, so every account starts here." />

    <form method="POST" action="{{ route('admin.users.store') }}" class="max-w-xl">
        @csrf

        <x-card>
            @include('admin.users._form', ['user' => null, 'roles' => $roles])

            <x-slot:footer>
                <div class="flex justify-end gap-2">
                    <a href="{{ route('admin.users.index') }}"
                       class="btn btn-secondary">
                        Cancel
                    </a>
                    <button type="submit"
                            class="btn btn-primary">
                        Create account
                    </button>
                </div>
            </x-slot:footer>
        </x-card>
    </form>
</x-app-layout>
