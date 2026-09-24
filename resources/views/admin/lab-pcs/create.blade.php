<x-app-layout>
    <x-slot name="title">Add machine</x-slot>

    <x-page-header title="Add lab PC"
                   subtitle="Every machine belongs to a location and carries a unique asset tag." />

    <form method="POST" action="{{ route('admin.lab-pcs.store') }}" class="max-w-2xl">
        @csrf
        <x-card>
            @include('admin.lab-pcs._form', ['labPc' => null, 'locations' => $locations, 'statuses' => $statuses])
            <x-slot:footer>
                <div class="flex justify-end gap-2">
                    <a href="{{ route('admin.lab-pcs.index') }}"
                       class="btn btn-secondary">Cancel</a>
                    <button type="submit"
                            class="btn btn-primary">Add machine</button>
                </div>
            </x-slot:footer>
        </x-card>
    </form>
</x-app-layout>
