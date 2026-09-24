{{-- The 1h / 6h / 24h switch. Reads and writes `range` on the enclosing
     networkMonitor() component, so the page chart and the detail chart move
     together. --}}
<div class="inline-flex gap-0.5 rounded-lg bg-sunken p-0.5" role="group" aria-label="Time range">
    @foreach (['1h', '6h', '24h'] as $option)
        <button type="button" @click="range = '{{ $option }}'" :aria-pressed="(range === '{{ $option }}').toString()"
                class="min-w-[2.5rem] rounded-md px-2.5 py-1 text-xs font-medium tabular-nums transition"
                :class="range === '{{ $option }}' ? 'bg-surface text-fg shadow-xs' : 'text-fg-muted hover:text-fg'">{{ $option }}</button>
    @endforeach
</div>
