<x-app-layout>
    <x-slot name="title">{{ $template->label() }}</x-slot>

    <x-page-header :title="$template->label()"
                   :subtitle="$template->is_active ? 'This is the checklist assistants are using.' : 'Not active. Assistants are not using this version.'">
        <x-slot:actions>
            @unless ($template->is_active)
                <form method="POST" action="{{ route('admin.checklists.activate', $template) }}">
                    @csrf
                    @method('PATCH')
                    <button type="submit"
                            class="btn btn-primary">
                        Make active
                    </button>
                </form>
            @endunless
        </x-slot:actions>
    </x-page-header>

    @if ($isLocked)
        {{-- The single most surprising behaviour in this module, so it is said
             before the edit rather than after it. --}}
        <x-alert type="warning" class="mb-4" title="This checklist has been used">
            Inspections point at these exact items, so saving will create
            <strong>version {{ $template->version + 1 }}</strong> rather than change this one.
            Version {{ $template->version }} is kept so past inspections still read correctly.
        </x-alert>
    @endif

    <form method="POST" action="{{ route('admin.checklists.update', $template) }}" class="max-w-3xl">
        @csrf
        @method('PATCH')
        <x-card>
            @include('admin.checklists._form', ['template' => $template, 'categories' => $categories])
            <x-slot:footer>
                <div class="flex items-center justify-between gap-2">
                    @if ($isLocked || $template->is_active)
                        <span class="text-xs text-fg-subtle">
                            {{ $isLocked ? 'Used by an inspection, so it cannot be deleted.' : 'Active. Activate another before deleting.' }}
                        </span>
                    @else
                        <button type="button" @click="$dispatch('open-modal', 'delete-checklist')"
                                class="btn btn-ghost text-red-700 hover:bg-red-50">Delete</button>
                    @endif

                    <div class="flex gap-2">
                        <a href="{{ route('admin.checklists.index') }}"
                           class="btn btn-secondary">Cancel</a>
                        <button type="submit"
                                class="btn btn-primary">
                            {{ $isLocked ? 'Save as version '.($template->version + 1) : 'Save changes' }}
                        </button>
                    </div>
                </div>
            </x-slot:footer>
        </x-card>
    </form>

    @unless ($isLocked || $template->is_active)
        <x-modal name="delete-checklist" title="Delete {{ $template->label() }}?">
            <p>No inspection has used this checklist, so nothing depends on it.</p>
            <x-slot:footer>
                <button type="button" @click="$dispatch('close-modal', 'delete-checklist')"
                        class="btn btn-secondary">Cancel</button>
                <form method="POST" action="{{ route('admin.checklists.destroy', $template) }}">
                    @csrf
                    @method('DELETE')
                    <button type="submit"
                            class="btn btn-danger">Delete</button>
                </form>
            </x-slot:footer>
        </x-modal>
    @endunless
</x-app-layout>
