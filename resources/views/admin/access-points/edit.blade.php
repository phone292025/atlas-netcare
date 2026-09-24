<x-app-layout>
    <x-slot name="title">Edit {{ $accessPoint->ap_code }}</x-slot>

    <x-page-header :title="'Edit '.$accessPoint->ap_code" :subtitle="$accessPoint->location?->label()">
        <x-slot:actions>
            <a href="{{ route('admin.aps.show', $accessPoint) }}"
               class="rounded-md bg-white px-3 py-2 text-sm font-medium text-slate-700 ring-1 ring-slate-300 hover:bg-slate-50">View</a>
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
                            class="rounded-md px-3 py-2 text-sm font-medium text-red-700 hover:bg-red-50">Remove</button>
                    <div class="flex gap-2">
                        <a href="{{ route('admin.aps.index') }}"
                           class="rounded-md bg-white px-3 py-2 text-sm font-medium text-slate-700 ring-1 ring-slate-300 hover:bg-slate-50">Cancel</a>
                        <button type="submit"
                                class="rounded-md bg-brand-600 px-3 py-2 text-sm font-medium text-white hover:bg-brand-700">Save changes</button>
                    </div>
                </div>
            </x-slot:footer>
        </x-card>
    </form>

    <x-modal name="confirm-remove-ap" title="Remove {{ $accessPoint->ap_code }}?">
        <p>It stops being polled and drops off the dashboard. Its telemetry readings
           and alarm history are kept — this is not a deletion, and it can be brought back.</p>
        <p class="mt-2 text-slate-500">If the unit is only coming down for planned work,
           set its status to <span class="font-medium">Under maintenance</span> instead.
           That suppresses alarms without losing it from the dashboard.</p>
        <x-slot:footer>
            <button type="button" @click="$dispatch('close-modal', 'confirm-remove-ap')"
                    class="rounded-md bg-white px-3 py-2 text-sm font-medium text-slate-700 ring-1 ring-slate-300">Cancel</button>
            <form method="POST" action="{{ route('admin.aps.destroy', $accessPoint) }}">
                @csrf
                @method('DELETE')
                <button type="submit"
                        class="rounded-md bg-red-600 px-3 py-2 text-sm font-medium text-white hover:bg-red-700">Remove access point</button>
            </form>
        </x-slot:footer>
    </x-modal>
</x-app-layout>
