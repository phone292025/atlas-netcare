<x-app-layout>
    <x-slot name="title">Add agent</x-slot>

    <x-page-header title="Add agent"
                   subtitle="Atlas makes a token for it on the next page. Then choose which devices it watches." />

    <form method="POST" action="{{ route('admin.agents.store') }}" class="max-w-2xl">
        @csrf
        <x-card>
            <x-form.input name="name" label="Name" required autofocus
                          placeholder="Level 5 probe"
                          help="Where it runs, so you can tell agents apart." />
            <x-slot:footer>
                <div class="flex justify-end gap-2">
                    <a href="{{ route('admin.agents.index') }}" class="btn btn-secondary">Cancel</a>
                    <button type="submit" class="btn btn-primary">Add agent and make its token</button>
                </div>
            </x-slot:footer>
        </x-card>
    </form>
</x-app-layout>
