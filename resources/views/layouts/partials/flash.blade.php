{{--
    Flash messages, for every page, from four session keys: `status` (Breeze's),
    `success`, `warning` and `error`. A page never adds its own banner.

        return redirect()->route('...')->with('success', 'Ticket resolved.');

    Success fades after six seconds and a warning after ten. An error stays
    until it is closed, because it usually says what to fix.
--}}
@php
    $flashes = collect(['status' => 'success', 'success' => 'success', 'warning' => 'warning', 'error' => 'error'])
        ->filter(fn (string $type, string $key) => session()->has($key))
        ->map(fn (string $type, string $key) => ['type' => $type, 'message' => session($key)]);
@endphp

@if ($flashes->isNotEmpty())
    <div class="pointer-events-none fixed inset-x-4 top-16 z-toast flex flex-col items-stretch gap-2 sm:left-auto sm:right-6 sm:w-[25rem]">
        @foreach ($flashes as $flash)
            @php
                [$tone, $glyph, $wait] = match ($flash['type']) {
                    'error' => [\App\Support\Tone::URGENT, 'x-circle', 0],
                    'warning' => [\App\Support\Tone::ATTENTION, 'warning', 10000],
                    default => [\App\Support\Tone::HEALTHY, 'check-circle', 6000],
                };
            @endphp
            <div x-data="toast({{ $wait }})" x-show="shown"
                 x-transition:leave="transition duration-200 ease-in"
                 x-transition:leave-start="opacity-100" x-transition:leave-end="-translate-y-1 opacity-0"
                 role="{{ $flash['type'] === 'error' ? 'alert' : 'status' }}"
                 class="pointer-events-auto flex items-start gap-3 rounded-lg border border-line bg-raised p-3.5 shadow-lg">
                <x-icon :name="$glyph" :fill="true" class="mt-px size-5 flex-none {{ \App\Support\Tone::accent($tone) }}" />
                <p class="min-w-0 flex-1 text-sm text-fg">{{ $flash['message'] }}</p>
                <button type="button" @click="shown = false" class="-m-1 flex-none rounded p-1 text-fg-subtle hover:text-fg"
                        aria-label="Dismiss message">
                    <x-icon name="close" class="size-4" />
                </button>
            </div>
        @endforeach
    </div>
@endif
