import './bootstrap';

import Alpine from 'alpinejs';
import Chart from 'chart.js/auto';

// Alpine drives all small client-side interactions: dropdowns, modals, the
// mobile sidebar, expanding checklist remarks, and the dashboard poll timer.
// See docs/decisions/ADR-0006-alpine-chartjs-not-livewire.md
window.Alpine = Alpine;

// Chart.js draws every graph in the system. Blade components pass data in as a
// prop; the chart never knows whether the numbers came from the simulator or
// from live access points. See docs/07-data-strategy.md
window.Chart = Chart;

// Shared chart defaults so every graph in the system looks like it belongs to
// the same application. Per-chart options may override these.
Chart.defaults.font.family =
    'Figtree, ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif';
Chart.defaults.font.size = 12;
Chart.defaults.plugins.legend.position = 'bottom';
Chart.defaults.plugins.legend.labels.usePointStyle = true;
Chart.defaults.maintainAspectRatio = false;
Chart.defaults.animation.duration = 300;

Alpine.start();
