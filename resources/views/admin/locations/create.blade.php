<x-app-layout>
    <x-slot name="title">Add location</x-slot>

    <x-page-header title="Add location" subtitle="A lab, a library floor, or the cafeteria." />

    <form method="POST" action="{{ route('admin.locations.store') }}" class="max-w-xl">
        @csrf
        <x-card>
            @include('admin.locations._form', ['location' => null, 'types' => $types])
            <x-slot:footer>
                <div class="flex justify-end gap-2">
                    <a href="{{ route('admin.locations.index') }}" class="rounded-md bg-white px-3 py-2 text-sm font-medium text-slate-700 ring-1 ring-slate-300 hover:bg-slate-50">Cancel</a>
                    <button type="submit" class="rounded-md bg-brand-600 px-3 py-2 text-sm font-medium text-white hover:bg-brand-700">Add location</button>
                </div>
            </x-slot:footer>
        </x-card>
    </form>
</x-app-layout>
