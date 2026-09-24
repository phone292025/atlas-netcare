<x-app-layout>
    <x-slot name="title">New checklist</x-slot>

    <x-page-header title="New checklist template"
                   subtitle="Build the questions, then activate it when you are ready for assistants to use it." />

    <form method="POST" action="{{ route('admin.checklists.store') }}" class="max-w-3xl">
        @csrf
        <x-card>
            @include('admin.checklists._form', ['template' => null, 'categories' => $categories])
            <x-slot:footer>
                <div class="flex justify-end gap-2">
                    <a href="{{ route('admin.checklists.index') }}"
                       class="btn btn-secondary">Cancel</a>
                    <button type="submit"
                            class="btn btn-primary">Create checklist</button>
                </div>
            </x-slot:footer>
        </x-card>
    </form>
</x-app-layout>
