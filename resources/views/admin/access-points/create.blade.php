<x-app-layout>
    <x-slot name="title">Add access point</x-slot>

    <x-page-header title="Add access point"
                   subtitle="Registering an access point is what puts it on the network dashboard." />

    <form method="POST" action="{{ route('admin.aps.store') }}" class="max-w-2xl">
        @csrf
        <x-card>
            @include('admin.access-points._form', ['accessPoint' => null, 'locations' => $locations, 'statuses' => $statuses])
            <x-slot:footer>
                <div class="flex justify-end gap-2">
                    <a href="{{ route('admin.aps.index') }}"
                       class="btn btn-secondary">Cancel</a>
                    <button type="submit"
                            class="btn btn-primary">Add access point</button>
                </div>
            </x-slot:footer>
        </x-card>
    </form>
</x-app-layout>
