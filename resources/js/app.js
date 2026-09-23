import Alpine from 'alpinejs';
import Chart from 'chart.js/auto';

// Alpine drives all small client-side interactions: dropdowns, modals, the
// mobile sidebar, expanding checklist remarks, and the dashboard poll timer.
// See docs/decisions/ADR-0006-alpine-chartjs-not-livewire.md
window.Alpine = Alpine;

// Chart.js draws every graph in the system. Blade components pass data in as a
// prop; the chart never knows whether the numbers came from the simulator or
// from live access points. See docs/05-data-strategy.md
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

// Shared by the three screens that poll for telemetry (FR-302, ADR-0005).
// Defined before Alpine.start(), so they exist by the time any x-init runs.

// Call `refresh` every `seconds`, and not at all while the tab is hidden:
// nobody is reading it, and a laptop left open overnight would otherwise make
// thousands of pointless requests. Coming back refreshes at once.
window.pollWhileVisible = (refresh, seconds) => {
    let timer = null;
    const begin = () => {
        timer = setInterval(refresh, seconds * 1000);
    };

    begin();

    document.addEventListener('visibilitychange', () => {
        if (document.hidden) {
            clearInterval(timer);
            timer = null;
        } else if (timer === null) {
            refresh();
            begin();
        }
    });
};

// GET a JSON endpoint, throwing on any non-2xx so callers keep their last
// good data rather than rendering an error page's HTML.
window.fetchJson = async (url) => {
    const response = await fetch(url, { headers: { Accept: 'application/json' } });

    if (! response.ok) {
        throw new Error(`${url} answered ${response.status}`);
    }

    return response.json();
};

// "12s ago" / "3m ago", from an ISO 8601 moment.
window.ago = (iso) => {
    const seconds = Math.max(0, Math.round((Date.now() - new Date(iso).getTime()) / 1000));

    return seconds < 60 ? `${seconds}s ago` : `${Math.round(seconds / 60)}m ago`;
};

Alpine.start();
