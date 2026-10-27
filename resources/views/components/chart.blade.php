@props([
    'type' => 'line',
    'data' => [],
    'options' => [],
    'height' => 240,
    'tone' => null,
    'label' => null,
])

{{--
    A Chart.js canvas.

    Usage:
        <x-chart type="line"
                 :data="['labels' => $hours, 'datasets' => [['label' => 'Latency (ms)', 'data' => $latency]]]"
                 tone="attention"
                 label="Mean latency over 24 hours" />

    THIS COMPONENT NEVER QUERIES. It receives `data` already shaped. It cannot
    tell whether the numbers came from the simulator or from a probe agent
    on a real network, so moving a device from one to the other touches no
    view.

    HEIGHT IS EXPLICIT. resources/js/app.js sets maintainAspectRatio = false so
    charts fill their container, which means a container with no height
    collapses to nothing and the chart silently does not appear. `height` is a
    required-feeling prop with a sane default for that reason.

    COLOUR COMES FROM Tone. A canvas cannot use a Tailwind class, so
    Tone::chart() returns the same four tones as hex. Pass `tone` and the first
    dataset is coloured for you; pass explicit colours in `data` to override.

    ACCESSIBILITY: a canvas is opaque to a screen reader. `label` becomes the
    accessible name, and the figure keeps a visually hidden text alternative.
    Charts in this system always accompany a table or tiles carrying the same
    numbers — the chart is never the only way to reach the data.

    UPDATING: the dashboard polls every 20 seconds. Rather than re-rendering
    the page, dispatch a window event carrying this chart's id and new data:

        $dispatch('chart-data', { id: 'ap-latency', data: {...} })

    Chart.js then animates between states instead of flashing a new canvas.

    OPTIONS ARE CAST TO AN OBJECT before they reach Chart.js. An empty PHP
    array serialises to a JS ARRAY, and `new Chart(canvas, { options: [] })`
    throws "Cannot convert undefined or null to object" and draws nothing at
    all. Every caller until M7 happened to pass options, so this sat here from
    M5 without anybody hitting it, and a blank chart reads as missing data
    rather than as a crash.

    @see docs/decisions/ADR-0006-alpine-chartjs-not-livewire.md
    @see docs/decisions/ADR-0005-ajax-polling-not-websockets.md
--}}

@php
    $chartId = $attributes->get('id') ?? 'chart-'.Str::random(8);

    $payload = $data;

    // Colour the first dataset from the shared tone source unless the caller
    // already specified. Later datasets are the caller's business.
    if (filled($tone) && isset($payload['datasets'][0]) && ! isset($payload['datasets'][0]['borderColor'])) {
        $hex = \App\Support\Tone::chart($tone);
        $payload['datasets'][0]['borderColor'] = $hex;
        $payload['datasets'][0]['backgroundColor'] = $hex.'20'; // ~12% alpha
        $payload['datasets'][0]['fill'] = $payload['datasets'][0]['fill'] ?? true;
        $payload['datasets'][0]['tension'] = $payload['datasets'][0]['tension'] ?? 0.3;
    }
@endphp

<figure {{ $attributes->except('id')->merge(['class' => 'relative']) }}
        x-data="{
            chart: null,
            init() {
                if (typeof window.Chart === 'undefined') {
                    console.error('Chart.js is not loaded; check resources/js/app.js');
                    return;
                }

                this.chart = new window.Chart(this.$refs.canvas, {
                    type: @js($type),
                    data: @js($payload),
                    options: @js((object) $options),
                });
            },
            destroy() {
                this.chart?.destroy();
            },
        }"
        @chart-data.window="
            if ($event.detail?.id === @js($chartId) && this.chart) {
                this.chart.data = $event.detail.data;
                this.chart.update();
            }
        ">

    <div style="height: {{ (int) $height }}px">
        <canvas x-ref="canvas"
                id="{{ $chartId }}"
                @if (filled($label)) aria-label="{{ $label }}" role="img" @endif></canvas>
    </div>

    @if (filled($label))
        <figcaption class="sr-only">{{ $label }}</figcaption>
    @endif
</figure>
