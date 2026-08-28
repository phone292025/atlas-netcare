<x-app-layout>
    <x-slot name="title">Add user</x-slot>

    <x-page-header title="Add user"
                   subtitle="Atlas NetCare has no self-registration — every account starts here." />

    <form method="POST" action="{{ route('admin.users.store') }}" class="max-w-xl">
        @csrf

        <x-card>
            @include('admin.users._form', ['user' => null, 'roles' => $roles])

            <x-slot:footer>
                <div class="flex justify-end gap-2">
                    <a href="{{ route('admin.users.index') }}"
                       class="rounded-md bg-white px-3 py-2 text-sm font-medium text-slate-700 ring-1 ring-slate-300 hover:bg-slate-50">
                        Cancel
                    </a>
                    <button type="submit"
                            class="rounded-md bg-brand-600 px-3 py-2 text-sm font-medium text-white hover:bg-brand-700">
                        Create account
                    </button>
                </div>
            </x-slot:footer>
        </x-card>
    </form>
</x-app-layout>
