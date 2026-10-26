<x-app-layout>
    <x-slot name="title">Edit {{ $accessPoint->code }}</x-slot>

    <x-page-header :title="'Edit '.$accessPoint->code" :subtitle="$accessPoint->location?->label()">
        <x-slot:actions>
            <a href="{{ route('admin.aps.show', $accessPoint) }}"
               class="btn btn-secondary">View</a>
        </x-slot:actions>
    </x-page-header>

    <form method="POST" action="{{ route('admin.aps.update', $accessPoint) }}" class="max-w-2xl">
        @csrf
        @method('PATCH')
        <x-card>
            @include('admin.access-points._form', ['accessPoint' => $accessPoint, 'locations' => $locations, 'statuses' => $statuses])
            <x-slot:footer>
                <div class="flex items-center justify-between gap-2">
                    <button type="button" @click="$dispatch('open-modal', 'confirm-remove-ap')"
                            class="btn btn-ghost text-red-700 hover:bg-red-50">Remove</button>
                    <div class="flex gap-2">
                        <a href="{{ route('admin.aps.index') }}"
                           class="btn btn-secondary">Cancel</a>
                        <button type="submit"
                                class="btn btn-primary">Save changes</button>
                    </div>
                </div>
            </x-slot:footer>
        </x-card>
    </form>

    <x-modal name="confirm-remove-ap" title="Remove {{ $accessPoint->code }}?">
        <p>It stops being polled and drops off the dashboard. Its telemetry readings
           and alarm history are kept. This is not a deletion, and it can be brought back.</p>
        <p class="mt-2 text-fg-muted">If the unit is only coming down for planned work,
           set its status to <span class="font-medium">Under maintenance</span> instead.
           That suppresses alarms without losing it from the dashboard.</p>
        <x-slot:footer>
            <button type="button" @click="$dispatch('close-modal', 'confirm-remove-ap')"
                    class="btn btn-secondary">Cancel</button>
            <form method="POST" action="{{ route('admin.aps.destroy', $accessPoint) }}">
                @csrf
                @method('DELETE')
                <button type="submit"
                        class="btn btn-danger">Remove access point</button>
            </form>
        </x-slot:footer>
    </x-modal>
</x-app-layout>
