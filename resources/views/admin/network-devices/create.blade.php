<x-app-layout>
    <x-slot name="title">Add network device</x-slot>

    <x-page-header title="Add network device"
                   subtitle="A switch, router or server. Access points are added under Access points." />

    <form method="POST" action="{{ route('admin.devices.store') }}" class="max-w-2xl">
        @csrf
        <x-card>
            @include('admin.network-devices._form')
            <x-slot:footer>
                <div class="flex justify-end gap-2">
                    <a href="{{ route('admin.devices.index') }}" class="btn btn-secondary">Cancel</a>
                    <button type="submit" class="btn btn-primary">Add device</button>
                </div>
            </x-slot:footer>
        </x-card>
    </form>
</x-app-layout>
