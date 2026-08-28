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
                       class="rounded-md bg-white px-3 py-2 text-sm font-medium text-slate-700 ring-1 ring-slate-300 hover:bg-slate-50">Cancel</a>
                    <button type="submit"
                            class="rounded-md bg-brand-600 px-3 py-2 text-sm font-medium text-white hover:bg-brand-700">Create checklist</button>
                </div>
            </x-slot:footer>
        </x-card>
    </form>
</x-app-layout>
