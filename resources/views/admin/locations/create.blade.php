<x-app-layout>
    <x-slot name="title">Add location</x-slot>

    <x-page-header title="Add location" subtitle="A lab, a library floor, or the cafeteria." />

    <form method="POST" action="{{ route('admin.locations.store') }}" class="max-w-xl">
        @csrf
        <x-card>
            @include('admin.locations._form', ['location' => null, 'types' => $types])
            <x-slot:footer>
                <div class="flex justify-end gap-2">
                    <a href="{{ route('admin.locations.index') }}" class="btn btn-secondary">Cancel</a>
                    <button type="submit" class="btn btn-primary">Add location</button>
                </div>
            </x-slot:footer>
        </x-card>
    </form>
</x-app-layout>
