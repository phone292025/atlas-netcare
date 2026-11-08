import { expect, test } from './fixtures.js';

/**
 * Flow 4: an engineer opens the Network Monitor. Its places, list and panel
 * are drawn by Alpine and its charts by Chart.js, so the HTML alone proves
 * none of it.
 *
 * The scheduler keeps simulating readings while the test runs, so a device's
 * status can change between two looks. The test compares the screen with
 * itself, never with a status from the seed. global-setup gives it a
 * degraded switch with an open incident, so "Needs attention" has a row.
 */

/** How many points each series of a Chart.js chart on this canvas holds. */
const plotted = (canvas) => canvas.evaluate((element) => window.Chart.getChart(element)?.data.datasets.map((set) => set.data.length) ?? []);

test('an engineer opens the Network Monitor', async ({ signedIn }) => {
    const engineer = await signedIn('engineer');
    await engineer.goto('/engineer/monitor');
    await expect(engineer.getByRole('heading', { name: 'Network monitor', level: 2 })).toBeVisible();

    // What needs attention comes first, with a way to look at it.
    await expect(engineer.getByRole('button', { name: /^Look at / }).first()).toBeVisible();

    // Every device is in a place, and the counts add up to the estate.
    const total = Number((await engineer.getByRole('button', { name: /^All \d+$/ }).textContent()).match(/\d+/)[0]);
    expect(total).toBeGreaterThan(0);
    await expect.poll(() => engineer.locator('section[aria-label] button').count()).toBe(total);

    // One chart per unit, each drawn.
    for (const name of [/^Connected clients across every access point/, /^Throughput across every access point/, /^Average latency across every device/]) {
        const series = await plotted(engineer.getByRole('img', { name }));
        expect(series.length).toBe(1);
        expect(series[0]).toBeGreaterThan(0);
    }

    const refreshed = engineer.waitForResponse((response) => /\/api\/monitor(\?|$)/.test(response.url()));
    await engineer.getByRole('button', { name: 'Refresh' }).click();
    expect((await refreshed).ok()).toBe(true);

    // The list shows the devices a count stands for, worst first.
    await engineer.getByRole('button', { name: 'List', exact: true }).click();
    const rows = engineer.getByRole('region', { name: 'Devices, scrollable' }).locator('tbody tr');
    await expect(rows).toHaveCount(total);
    const online = engineer.getByRole('button', { name: /^Online \d+$/ });
    await online.click();
    await expect(rows).toHaveCount(Number((await online.textContent()).match(/\d+/)[0]));
    await engineer.getByRole('button', { name: /^All \d+$/ }).click();

    // A device opens in the panel beside the page, says where the fault is,
    // draws its own day, and the link to it can be shared.
    const first = rows.first().getByRole('button');
    const code = (await first.locator('span').first().textContent()).trim();
    await first.click();
    const panel = engineer.getByRole('dialog', { name: code });
    await expect(panel).toBeVisible();
    await expect(panel.getByText('Where the fault is')).toBeVisible();
    await expect(panel.getByRole('button', { name: 'Close' })).toBeFocused();
    await expect.poll(() => plotted(panel.getByRole('img', { name: new RegExp(` for ${code}$`) }))).not.toEqual([]);
    expect(new URL(engineer.url()).searchParams.get('device')).toBe(code);

    await engineer.keyboard.press('Escape');
    await expect(panel).toBeHidden();
    await expect(first).toBeFocused();

    // Opened from its link, the panel is there on arrival.
    await engineer.goto(`/engineer/monitor?device=${encodeURIComponent(code)}`);
    await expect(engineer.getByRole('dialog', { name: code })).toBeVisible();
});
