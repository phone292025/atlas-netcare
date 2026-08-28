<x-app-layout>
    <x-slot name="title">Edit {{ $labPc->asset_tag }}</x-slot>

    <x-page-header :title="'Edit '.$labPc->asset_tag" :subtitle="$labPc->location?->label()">
        <x-slot:actions>
            <a href="{{ route('admin.lab-pcs.show', $labPc) }}"
               class="rounded-md bg-white px-3 py-2 text-sm font-medium text-slate-700 ring-1 ring-slate-300 hover:bg-slate-50">View</a>
        </x-slot:actions>
    </x-page-header>

    <form method="POST" action="{{ route('admin.lab-pcs.update', $labPc) }}" class="max-w-2xl">
        @csrf
        @method('PATCH')
        <x-card>
            @include('admin.lab-pcs._form', ['labPc' => $labPc, 'locations' => $locations, 'statuses' => $statuses])
            <x-slot:footer>
                <div class="flex items-center justify-between gap-2">
                    <button type="button" @click="$dispatch('open-modal', 'confirm-retire-pc')"
                            class="rounded-md px-3 py-2 text-sm font-medium text-red-700 hover:bg-red-50">Retire</button>
                    <div class="flex gap-2">
                        <a href="{{ route('admin.lab-pcs.index') }}"
                           class="rounded-md bg-white px-3 py-2 text-sm font-medium text-slate-700 ring-1 ring-slate-300 hover:bg-slate-50">Cancel</a>
                        <button type="submit"
                                class="rounded-md bg-brand-600 px-3 py-2 text-sm font-medium text-white hover:bg-brand-700">Save changes</button>
                    </div>
                </div>
            </x-slot:footer>
        </x-card>
    </form>

    <x-modal name="confirm-retire-pc" title="Retire {{ $labPc->asset_tag }}?">
        <p>It drops out of inspection rounds and risk scoring. Its inspection history
           and tickets are kept — this is not a deletion, and it can be brought back.</p>
        <x-slot:footer>
            <button type="button" @click="$dispatch('close-modal', 'confirm-retire-pc')"
                    class="rounded-md bg-white px-3 py-2 text-sm font-medium text-slate-700 ring-1 ring-slate-300">Cancel</button>
            <form method="POST" action="{{ route('admin.lab-pcs.destroy', $labPc) }}">
                @csrf
                @method('DELETE')
                <button type="submit"
                        class="rounded-md bg-red-600 px-3 py-2 text-sm font-medium text-white hover:bg-red-700">Retire machine</button>
            </form>
        </x-slot:footer>
    </x-modal>
</x-app-layout>
