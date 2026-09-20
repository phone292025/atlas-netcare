{{-- The 1h / 6h / 24h switch. Reads and writes `range` on the enclosing
     networkMonitor() component, so the page chart and the detail chart move
     together. --}}
<div class="inline-flex gap-0.5 rounded-md bg-white p-0.5 ring-1 ring-inset ring-slate-200" role="group" aria-label="Time range">
    @foreach (['1h', '6h', '24h'] as $option)
        <button type="button" @click="range = '{{ $option }}'" :aria-pressed="(range === '{{ $option }}').toString()"
                class="min-w-[2.25rem] rounded px-2.5 py-1 text-xs transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500"
                :class="range === '{{ $option }}' ? 'bg-slate-100 font-medium text-slate-900' : 'text-slate-500 hover:text-slate-900'">{{ $option }}</button>
    @endforeach
</div>
