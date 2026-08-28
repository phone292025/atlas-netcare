<x-app-layout>
    <x-slot name="title">{{ $location->code }}</x-slot>

    <div class="mx-auto max-w-2xl">
        <x-page-header :title="$location->code" :subtitle="$location->name">
            <x-slot:actions>
                <a href="{{ route('assistant.inspections.index') }}"
                   class="rounded-md bg-white px-3 py-2 text-sm font-medium text-slate-700 ring-1 ring-slate-300 hover:bg-slate-50">
                    All locations
                </a>
            </x-slot:actions>
        </x-page-header>

        @unless ($hasTemplate)
            <x-alert type="warning" class="mb-4" title="No checklist yet">
                An administrator needs to activate a checklist template before
                inspections can be started.
            </x-alert>
        @endunless

        <x-card title="Machines in this location"
                subtitle="Already checked today are marked. Retired machines are not listed." flush>
            @if ($labPcs->isEmpty())
                <x-empty-state title="No machines here"
                               message="Every machine in this location is retired, or none have been added yet."
                               icon="desktop" />
            @else
                <ul class="divide-y divide-slate-100">
                    @foreach ($labPcs as $labPc)
                        <li class="flex items-center justify-between gap-3 px-6 py-3">
                            <div class="min-w-0">
                                <p class="text-sm font-medium text-slate-900">{{ $labPc->asset_tag }}</p>
                                <p class="text-xs text-slate-500">
                                    {{ $labPc->hostname ?? 'No hostname' }}
                                    @if ($labPc->inspected_today_count > 0)
                                        · <span class="text-emerald-600">checked today</span>
                                    @endif
                                </p>
                            </div>

                            <form method="POST" action="{{ route('assistant.inspections.store') }}" class="flex-none">
                                @csrf
                                <input type="hidden" name="lab_pc_id" value="{{ $labPc->id }}">
                                <button type="submit"
                                        @disabled(! $hasTemplate)
                                        class="rounded-md px-3 py-2 text-sm font-medium
                                               {{ $labPc->inspected_today_count > 0
                                                   ? 'bg-white text-slate-700 ring-1 ring-slate-300 hover:bg-slate-50'
                                                   : 'bg-brand-600 text-white hover:bg-brand-700' }}
                                               disabled:cursor-not-allowed disabled:opacity-50">
                                    {{-- Re-checking is allowed: a machine fixed
                                         after a failed inspection needs looking
                                         at again the same day. --}}
                                    {{ $labPc->inspected_today_count > 0 ? 'Check again' : 'Inspect' }}
                                </button>
                            </form>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-card>
    </div>
</x-app-layout>
