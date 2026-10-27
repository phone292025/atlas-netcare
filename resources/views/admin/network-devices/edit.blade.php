<x-app-layout>
    <x-slot name="title">Edit {{ $device->code }}</x-slot>

    <x-page-header :title="'Edit '.$device->code" :subtitle="$device->location?->label()">
        <x-slot:actions>
            <a href="{{ route('admin.devices.show', $device) }}" class="btn btn-secondary">View</a>
        </x-slot:actions>
    </x-page-header>

    <form method="POST" action="{{ route('admin.devices.update', $device) }}" class="max-w-2xl">
        @csrf
        @method('PATCH')
        <x-card>
            @include('admin.network-devices._form')
            <x-slot:footer>
                <div class="flex items-center justify-between gap-2">
                    <button type="button" @click="$dispatch('open-modal', 'confirm-retire-device')"
                            class="btn btn-ghost text-red-700 hover:bg-red-50">Retire</button>
                    <div class="flex gap-2">
                        <a href="{{ route('admin.devices.index') }}" class="btn btn-secondary">Cancel</a>
                        <button type="submit" class="btn btn-primary">Save changes</button>
                    </div>
                </div>
            </x-slot:footer>
        </x-card>
    </form>

    <x-modal name="confirm-retire-device" title="Retire {{ $device->code }}?">
        <p>It drops off the inventory and stops being watched. Its readings, tickets and
           history are kept, and it can be brought back.</p>
        <p class="mt-2 text-fg-muted">Anything still connected to it has to be connected to
           another device first. For planned work, set its status to
           <span class="font-medium">Under maintenance</span> instead.</p>
        <x-slot:footer>
            <button type="button" @click="$dispatch('close-modal', 'confirm-retire-device')"
                    class="btn btn-secondary">Cancel</button>
            <form method="POST" action="{{ route('admin.devices.destroy', $device) }}">
                @csrf
                @method('DELETE')
                <button type="submit" class="btn btn-danger">Retire device</button>
            </form>
        </x-slot:footer>
    </x-modal>
</x-app-layout>
