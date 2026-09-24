import Alpine from 'alpinejs';
import Chart from 'chart.js/auto';

// Alpine drives every small interaction: menus, dialogs, the mobile drawer,
// the command menu, checklist remarks and the dashboard poll timer.
// See docs/decisions/ADR-0006-alpine-chartjs-not-livewire.md
window.Alpine = Alpine;

// Chart.js draws every graph. Blade passes data in as a prop; a chart never
// knows whether the numbers came from the simulator or a live access point.
window.Chart = Chart;

/*
 | Theme. The head script has already set data-theme before first paint; this
 | keeps it in step afterwards. The choice is 'system', 'light' or 'dark', kept
 | in this browser's localStorage and nowhere else.
 */
const THEME_KEY = 'atlas-theme';
const darkQuery = window.matchMedia('(prefers-color-scheme: dark)');

const storedTheme = () => {
    try {
        return localStorage.getItem(THEME_KEY) || 'system';
    } catch (e) {
        return 'system';
    }
};

const applyTheme = (choice) => {
    const dark = choice === 'dark' || (choice === 'system' && darkQuery.matches);
    document.documentElement.dataset.theme = dark ? 'dark' : 'light';
    window.dispatchEvent(new CustomEvent('atlas:theme'));
};

Alpine.store('theme', {
    choice: storedTheme(),
    set(choice) {
        this.choice = choice;
        try {
            if (choice === 'system') {
                localStorage.removeItem(THEME_KEY);
            } else {
                localStorage.setItem(THEME_KEY, choice);
            }
        } catch (e) {}
        applyTheme(choice);
    },
});

darkQuery.addEventListener('change', () => {
    if (Alpine.store('theme').choice === 'system') {
        applyTheme('system');
    }
});

/*
 | Charts read their neutral colours from the same CSS variables as the page,
 | and are recoloured when the theme changes. Chart.js copies defaults into a
 | chart when it is built, so existing charts are updated one by one.
 | Dataset colours still come from App\Support\Tone::chart().
 */
const token = (name, alpha = 1) => {
    const rgb = getComputedStyle(document.documentElement).getPropertyValue(`--${name}`).trim();

    return rgb ? `rgb(${rgb} / ${alpha})` : undefined;
};

// For page scripts that colour their own datasets: themeToken('c-brand-500').
window.themeToken = token;

const chartColours = () => ({
    text: token('fg-muted'),
    grid: token('line', 0.8),
    tooltip: token('raised'),
    tooltipText: token('fg'),
    tooltipBorder: token('line-strong'),
});

const themeChartDefaults = () => {
    const c = chartColours();
    Chart.defaults.color = c.text;
    Chart.defaults.borderColor = c.grid;
    Chart.defaults.plugins.tooltip.backgroundColor = c.tooltip;
    Chart.defaults.plugins.tooltip.titleColor = c.tooltipText;
    Chart.defaults.plugins.tooltip.bodyColor = c.tooltipText;
    Chart.defaults.plugins.tooltip.borderColor = c.tooltipBorder;

    return c;
};

Chart.defaults.font.family = '"Geist Variable", ui-sans-serif, system-ui, sans-serif';
Chart.defaults.font.size = 12;
Chart.defaults.plugins.legend.position = 'bottom';
Chart.defaults.plugins.legend.labels.usePointStyle = true;
Chart.defaults.plugins.legend.labels.boxWidth = 8;
Chart.defaults.plugins.tooltip.borderWidth = 1;
Chart.defaults.plugins.tooltip.padding = 10;
Chart.defaults.plugins.tooltip.cornerRadius = 8;
Chart.defaults.maintainAspectRatio = false;
Chart.defaults.animation.duration = 300;
themeChartDefaults();

window.addEventListener('atlas:theme', () => {
    const c = themeChartDefaults();

    Object.values(Chart.instances).forEach((chart) => {
        Object.values(chart.options.scales ?? {}).forEach((scale) => {
            if (scale.ticks) scale.ticks.color = c.text;
            if (scale.grid) scale.grid.color = c.grid;
            if (scale.title) scale.title.color = c.text;
        });
        if (chart.options.plugins?.legend?.labels) {
            chart.options.plugins.legend.labels.color = c.text;
        }
        chart.update('none');
    });
});

/*
 | The command menu: Ctrl+K or / opens it, type to filter, arrows to move,
 | Enter to go. The server supplies the entries; this only filters them.
 */
Alpine.data('commandMenu', (items) => ({
    open: false,
    query: '',
    index: 0,
    items,

    get results() {
        const q = this.query.trim().toLowerCase();
        if (q === '') {
            return this.items;
        }

        return this.items.filter((item) => `${item.label} ${item.hint}`.toLowerCase().includes(q));
    },

    show() {
        this.open = true;
        this.query = '';
        this.index = 0;
        this.$nextTick(() => this.$refs.input?.focus());
    },

    hide() {
        this.open = false;
    },

    move(step) {
        const count = this.results.length;
        if (count === 0) return;
        this.index = (this.index + step + count) % count;
        this.$nextTick(() => {
            this.$refs.list?.querySelector('[data-active="true"]')?.scrollIntoView({ block: 'nearest' });
        });
    },

    go(item = this.results[this.index]) {
        if (item) {
            window.location.href = item.url;
        }
    },

    onKey(event) {
        const typing = ['INPUT', 'TEXTAREA', 'SELECT'].includes(event.target.tagName) || event.target.isContentEditable;
        if ((event.key === 'k' && (event.metaKey || event.ctrlKey)) || (event.key === '/' && !typing)) {
            event.preventDefault();
            this.open ? this.hide() : this.show();
        }
    },
}));

// A flash message that removes itself. Errors pass 0 and stay until closed.
Alpine.data('toast', (milliseconds = 6000) => ({
    shown: true,
    init() {
        if (milliseconds > 0) {
            setTimeout(() => (this.shown = false), milliseconds);
        }
    },
}));

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

    if (!response.ok) {
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
