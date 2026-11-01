import { accounts, expect, test } from './fixtures.js';

/**
 * NFR-05 and NFR-10, checked on every screen instead of by hand.
 *
 * - At 375 px wide nothing scrolls sideways.
 * - The keyboard alone signs in, and the first Tab offers "Skip to content".
 * - Tab reaches every control with a visible change on the focused element,
 *   never gets stuck, and never lands on something hidden or off screen,
 *   like the links of a closed drawer.
 *
 * "Visible change" compares the element's outline, shadow, background, border
 * and underline focused and unfocused, with transitions off so the focused
 * style is already in place when it is read.
 */

const screens = {
    admin: [
        '/admin/monitor', '/admin/lab-pcs', '/admin/aps', '/admin/devices', '/admin/agents', '/admin/locations',
        '/admin/users', '/admin/audit', '/admin/roster', '/admin/shift-templates', '/admin/checklists', '/admin/reports',
        '/admin/lab-pcs/create', '/admin/users/create', '/notifications', '/profile',
    ],
    engineer: [
        '/engineer/dashboard', '/engineer/monitor', '/engineer/aps', '/engineer/traffic', '/engineer/tickets',
        '/engineer/risk', '/admin/reports', '/notifications', '/profile',
    ],
    assistant: [
        '/assistant/shifts/today', '/assistant/shifts', '/assistant/inspections', '/assistant/tickets',
        '/assistant/tickets/create', '/assistant/aps', '/assistant/monitor', '/notifications', '/profile',
    ],
};

const phone = { width: 375, height: 812 };

async function withoutTransitions(page) {
    await page.addStyleTag({ content: '*, *::before, *::after { transition: none !important; animation: none !important; }' });
}

/**
 * Tabs through the page from the top and returns what went wrong: stops
 * with no visible focus change, a stop that keeps focus, and stops that are
 * hidden or outside the window.
 */
async function sweep(page, stops = 120) {
    const problems = [];
    const seen = new Set();
    let previous = null;

    await page.evaluate(() => document.activeElement?.blur());

    for (let i = 0; i < stops; i++) {
        await page.keyboard.press('Tab');

        const stop = await page.evaluate(() => {
            const el = document.activeElement;

            if (!el || el === document.body) return null;

            const describe = `${el.tagName.toLowerCase()} "${(el.getAttribute('aria-label') || el.textContent || el.getAttribute('name') || '').trim().replace(/\s+/g, ' ').slice(0, 40)}"`;
            const look = () => {
                const s = getComputedStyle(el);
                return [s.outlineStyle, s.outlineWidth, s.outlineColor, s.boxShadow, s.backgroundColor, s.borderColor, s.textDecorationLine, s.color].join('|');
            };

            el.scrollIntoView({ block: 'center', inline: 'nearest' });
            const box = el.getBoundingClientRect();
            const focused = look();
            const marker = el.dataset.sweepId ??= String(Math.random());

            // The same element unfocused, then focused again for the next Tab.
            el.blur();
            const plain = look();
            el.focus();

            return {
                marker,
                describe,
                // Tab walks a date field's day, month and year in place.
                segmented: ['date', 'time', 'datetime-local', 'month', 'week'].includes(el.getAttribute('type')),
                visible: focused !== plain,
                onScreen: box.width > 0 && box.height > 0 && box.right > 0 && box.left < window.innerWidth + 1,
            };
        });

        if (stop === null) break;
        if (stop.marker === previous && stop.segmented) continue;
        if (stop.marker === previous) {
            problems.push(`focus stuck on ${stop.describe}`);
            break;
        }
        // Round the page and back to the top.
        if (seen.has(stop.marker)) break;
        seen.add(stop.marker);
        if (!stop.onScreen) problems.push(`focus went off screen to ${stop.describe}`);
        else if (!stop.visible) problems.push(`no visible focus on ${stop.describe}`);

        previous = stop.marker;
    }

    return problems;
}

test('the keyboard alone signs in, and the first Tab offers to skip to the content', async ({ page }) => {
    await page.goto('/login');
    const email = page.getByLabel('Email');

    for (let i = 0; i < 10 && !(await email.evaluate((el) => el === document.activeElement)); i++) {
        await page.keyboard.press('Tab');
    }

    await expect(email).toBeFocused();
    await page.keyboard.type(accounts.engineer.email);
    await page.keyboard.press('Tab');
    await page.keyboard.type(accounts.engineer.password);
    await page.keyboard.press('Enter');
    await expect(page).not.toHaveURL(/\/login/);

    await page.keyboard.press('Tab');
    const skip = page.getByRole('link', { name: 'Skip to content' });
    await expect(skip).toBeFocused();
    await expect(skip).toBeInViewport();
    await page.keyboard.press('Enter');
    await expect(page).toHaveURL(/#main$/);
});

test('on a phone the navigation drawer opens and closes from the keyboard', async ({ signedIn }) => {
    const page = await signedIn('engineer');
    await page.setViewportSize(phone);
    await page.goto('/engineer/dashboard');
    const sidebar = page.getByRole('complementary', { name: 'Sidebar' });
    const link = sidebar.getByRole('link', { name: 'Ticket queue' });

    // Closed, its links are out of the Tab order, not only off screen.
    await expect(link).toBeHidden();

    await page.getByRole('button', { name: 'Open navigation' }).focus();
    await page.keyboard.press('Enter');
    await expect(link).toBeInViewport();
    await link.focus();
    await expect(link).toBeFocused();

    await page.keyboard.press('Escape');
    await expect(link).toBeHidden();
});

for (const [role, paths] of Object.entries(screens)) {
    test(`every ${role} screen fits a phone without sideways scrolling`, async ({ signedIn }) => {
        const page = await signedIn(role);
        await page.setViewportSize(phone);
        const wide = [];

        for (const path of paths) {
            const response = await page.goto(path);
            expect(response.status(), path).toBe(200);
            const [scroll, client] = await page.evaluate(() => [document.documentElement.scrollWidth, document.documentElement.clientWidth]);
            if (scroll > client + 1) wide.push(`${path}: ${scroll}px wide in a ${client}px window`);
        }

        expect(wide).toEqual([]);
    });

    test(`every ${role} screen can be worked by keyboard`, async ({ signedIn }) => {
        test.setTimeout(240_000);
        const page = await signedIn(role);
        const found = [];

        for (const viewport of [{ width: 1440, height: 900 }, phone]) {
            await page.setViewportSize(viewport);

            for (const path of paths) {
                await page.goto(path);
                await withoutTransitions(page);
                for (const problem of await sweep(page)) found.push(`${viewport.width}px ${path}: ${problem}`);
            }
        }

        expect(found).toEqual([]);
    });
}
