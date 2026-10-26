import { expect, test } from './fixtures.js';

/**
 * Flow 4: an engineer opens the Network Monitor. Its chart is drawn by Chart.js
 * and its table is filtered in the browser, so the HTML alone proves neither.
 *
 * The scheduler keeps simulating readings while the test runs, so an access
 * point's status can change between two looks. The test compares the screen
 * with itself, never with a status from the seed.
 */

/** How many points each series of a Chart.js chart on this canvas holds. */
const plotted = (canvas) => canvas.evaluate((element) => window.Chart.getChart(element)?.data.datasets.map((set) => set.data.length) ?? []);

test('an engineer opens the Network Monitor', async ({ signedIn }) => {
    const engineer = await signedIn('engineer');
    await engineer.goto('/engineer/monitor');
    await expect(engineer.getByRole('heading', { name: 'Network monitor', level: 2 })).toBeVisible();

    const rows = engineer.getByRole('table').getByRole('row').filter({ has: engineer.getByRole('button', { name: /^Details for / }) });
    const all = Number((await engineer.getByRole('button', { name: /^All \d+$/ }).textContent()).match(/\d+/)[0]);
    expect(all).toBeGreaterThan(0);
    await expect(rows).toHaveCount(all);

    const chart = engineer.getByRole('img', { name: /^Connected clients and throughput across all access points/ });
    const series = await plotted(chart);
    expect(series.length).toBeGreaterThan(0);
    for (const points of series) expect(points).toBeGreaterThan(0);

    const refreshed = engineer.waitForResponse((response) => /\/api\/monitor(\?|$)/.test(response.url()));
    await engineer.getByRole('button', { name: 'Refresh' }).click();
    expect((await refreshed).ok()).toBe(true);

    // The status buttons filter the table to their own count.
    const online = engineer.getByRole('button', { name: /^Online \d+$/ });
    await online.click();
    const count = Number((await online.textContent()).match(/\d+/)[0]);
    await expect(rows).toHaveCount(count);
    await engineer.getByRole('button', { name: /^All \d+$/ }).click();

    const first = rows.first();
    const name = (await first.getByRole('cell').first().getByRole('paragraph').first().textContent()).trim();
    await first.getByRole('button', { name: `Details for ${name}` }).click();

    const details = engineer.getByRole('dialog', { name: 'Access point' });
    await expect(details.getByRole('heading', { name })).toBeVisible();
    await expect.poll(() => plotted(details.getByRole('img', { name: `Clients and throughput for ${name}` }))).not.toEqual([]);
});
