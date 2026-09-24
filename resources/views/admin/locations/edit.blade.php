<x-app-layout>
    <x-slot name="title">Edit {{ $location->code }}</x-slot>

    <x-page-header :title="'Edit '.$location->code" :subtitle="$location->name" />

    <form method="POST" action="{{ route('admin.locations.update', $location) }}" class="max-w-xl">
        @csrf
        @method('PATCH')
        <x-card>
            @include('admin.locations._form', ['location' => $location, 'types' => $types])
            <x-slot:footer>
                <div class="flex items-center justify-between gap-2">
                    <button type="button" @click="$dispatch('open-modal', 'confirm-delete-location')"
                            class="btn btn-ghost text-red-700 hover:bg-red-50">Delete</button>
                    <div class="flex gap-2">
                        <a href="{{ route('admin.locations.index') }}" class="btn btn-secondary">Cancel</a>
                        <button type="submit" class="btn btn-primary">Save changes</button>
                    </div>
                </div>
            </x-slot:footer>
        </x-card>
    </form>

    <x-modal name="confirm-delete-location" title="Delete {{ $location->code }}?">
        <p>A location can only be deleted while nothing lives in it. If it still
           holds machines or access points, move or retire those first.</p>
        <x-slot:footer>
            <button type="button" @click="$dispatch('close-modal', 'confirm-delete-location')"
                    class="btn btn-secondary">Cancel</button>
            <form method="POST" action="{{ route('admin.locations.destroy', $location) }}">
                @csrf
                @method('DELETE')
                <button type="submit" class="btn btn-danger">Delete location</button>
            </form>
        </x-slot:footer>
    </x-modal>
</x-app-layout>
