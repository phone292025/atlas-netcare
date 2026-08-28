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
                       class="rounded-md bg-white px-3 py-2 text-sm font-medium text-slate-700 ring-1 ring-slate-300 hover:bg-slate-50">Cancel</a>
                    <button type="submit"
                            class="rounded-md bg-brand-600 px-3 py-2 text-sm font-medium text-white hover:bg-brand-700">Add access point</button>
                </div>
            </x-slot:footer>
        </x-card>
    </form>
</x-app-layout>
