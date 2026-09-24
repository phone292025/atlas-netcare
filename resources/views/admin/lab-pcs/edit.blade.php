<x-app-layout>
    <x-slot name="title">Edit {{ $labPc->asset_tag }}</x-slot>

    <x-page-header :title="'Edit '.$labPc->asset_tag" :subtitle="$labPc->location?->label()">
        <x-slot:actions>
            <a href="{{ route('admin.lab-pcs.show', $labPc) }}"
               class="btn btn-secondary">View</a>
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
                            class="btn btn-ghost text-red-700 hover:bg-red-50">Retire</button>
                    <div class="flex gap-2">
                        <a href="{{ route('admin.lab-pcs.index') }}"
                           class="btn btn-secondary">Cancel</a>
                        <button type="submit"
                                class="btn btn-primary">Save changes</button>
                    </div>
                </div>
            </x-slot:footer>
        </x-card>
    </form>

    <x-modal name="confirm-retire-pc" title="Retire {{ $labPc->asset_tag }}?">
        <p>It drops out of inspection rounds and risk scoring. Its inspection history
           and tickets are kept. This is not a deletion, and it can be brought back.</p>
        <x-slot:footer>
            <button type="button" @click="$dispatch('close-modal', 'confirm-retire-pc')"
                    class="btn btn-secondary">Cancel</button>
            <form method="POST" action="{{ route('admin.lab-pcs.destroy', $labPc) }}">
                @csrf
                @method('DELETE')
                <button type="submit"
                        class="btn btn-danger">Retire machine</button>
            </form>
        </x-slot:footer>
    </x-modal>
</x-app-layout>
