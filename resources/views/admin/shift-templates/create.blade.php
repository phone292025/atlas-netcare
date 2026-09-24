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
                       class="btn btn-secondary">Cancel</a>
                    <button type="submit"
                            class="btn btn-primary">Add template</button>
                </div>
            </x-slot:footer>
        </x-card>
    </form>
</x-app-layout>
