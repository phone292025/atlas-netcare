<x-app-layout>
    <x-slot name="title">New shift template</x-slot>

    <x-page-header title="New shift template"
                   subtitle="A weekly pattern. Generating a roster turns it into dated shifts." />

    <form method="POST" action="{{ route('admin.shift-templates.store') }}" class="max-w-2xl">
        @csrf
        <x-card>
            @include('admin.shift-templates._form', ['template' => null, 'locations' => $locations, 'days' => $days])
            <x-slot:footer>
                <div class="flex justify-end gap-2">
                    <a href="{{ route('admin.shift-templates.index') }}"
                       class="rounded-md bg-white px-3 py-2 text-sm font-medium text-slate-700 ring-1 ring-slate-300 hover:bg-slate-50">Cancel</a>
                    <button type="submit"
                            class="rounded-md bg-brand-600 px-3 py-2 text-sm font-medium text-white hover:bg-brand-700">Add template</button>
                </div>
            </x-slot:footer>
        </x-card>
    </form>
</x-app-layout>
