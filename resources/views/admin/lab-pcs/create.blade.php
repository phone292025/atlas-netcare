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
                       class="rounded-md bg-white px-3 py-2 text-sm font-medium text-slate-700 ring-1 ring-slate-300 hover:bg-slate-50">Cancel</a>
                    <button type="submit"
                            class="rounded-md bg-brand-600 px-3 py-2 text-sm font-medium text-white hover:bg-brand-700">Add machine</button>
                </div>
            </x-slot:footer>
        </x-card>
    </form>
</x-app-layout>
