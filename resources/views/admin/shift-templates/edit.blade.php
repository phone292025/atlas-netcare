<x-app-layout>
    <x-slot name="title">{{ $template->name }}</x-slot>

    <x-page-header :title="$template->name" :subtitle="$template->label()" />

    <form method="POST" action="{{ route('admin.shift-templates.update', $template) }}" class="max-w-2xl">
        @csrf
        @method('PATCH')
        <x-card>
            @include('admin.shift-templates._form', ['template' => $template, 'locations' => $locations, 'days' => $days])
            <x-slot:footer>
                <div class="flex items-center justify-between gap-2">
                    @if ($hasShifts)
                        <span class="text-xs text-slate-400">Has generated shifts — deactivate rather than delete.</span>
                    @else
                        <button type="button" @click="$dispatch('open-modal', 'delete-template')"
                                class="rounded-md px-3 py-2 text-sm font-medium text-red-700 hover:bg-red-50">Delete</button>
                    @endif

                    <div class="flex gap-2">
                        <a href="{{ route('admin.shift-templates.index') }}"
                           class="rounded-md bg-white px-3 py-2 text-sm font-medium text-slate-700 ring-1 ring-slate-300 hover:bg-slate-50">Cancel</a>
                        <button type="submit"
                                class="rounded-md bg-brand-600 px-3 py-2 text-sm font-medium text-white hover:bg-brand-700">Save changes</button>
                    </div>
                </div>
            </x-slot:footer>
        </x-card>
    </form>

    @unless ($hasShifts)
        <x-modal name="delete-template" title="Delete {{ $template->name }}?">
            <p>No shifts have been generated from this pattern, so nothing depends on it.</p>
            <x-slot:footer>
                <button type="button" @click="$dispatch('close-modal', 'delete-template')"
                        class="rounded-md bg-white px-3 py-2 text-sm font-medium text-slate-700 ring-1 ring-slate-300">Cancel</button>
                <form method="POST" action="{{ route('admin.shift-templates.destroy', $template) }}">
                    @csrf
                    @method('DELETE')
                    <button type="submit"
                            class="rounded-md bg-red-600 px-3 py-2 text-sm font-medium text-white hover:bg-red-700">Delete</button>
                </form>
            </x-slot:footer>
        </x-modal>
    @endunless
</x-app-layout>
