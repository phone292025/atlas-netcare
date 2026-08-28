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
                            class="rounded-md px-3 py-2 text-sm font-medium text-red-700 hover:bg-red-50">Delete</button>
                    <div class="flex gap-2">
                        <a href="{{ route('admin.locations.index') }}" class="rounded-md bg-white px-3 py-2 text-sm font-medium text-slate-700 ring-1 ring-slate-300 hover:bg-slate-50">Cancel</a>
                        <button type="submit" class="rounded-md bg-brand-600 px-3 py-2 text-sm font-medium text-white hover:bg-brand-700">Save changes</button>
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
                    class="rounded-md bg-white px-3 py-2 text-sm font-medium text-slate-700 ring-1 ring-slate-300">Cancel</button>
            <form method="POST" action="{{ route('admin.locations.destroy', $location) }}">
                @csrf
                @method('DELETE')
                <button type="submit" class="rounded-md bg-red-600 px-3 py-2 text-sm font-medium text-white hover:bg-red-700">Delete location</button>
            </form>
        </x-slot:footer>
    </x-modal>
</x-app-layout>
