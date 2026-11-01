import { AxeBuilder } from '@axe-core/playwright';
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
 * - axe-core finds no WCAG 2.1 A or AA violation, in the light theme and the
 *   dark one (ADR-0018).
 *
 * "Visible change" compares the element's outline, shadow, background, border
 * and underline focused and unfocused, with transitions off so the focused
 * style is already in place when it is read.
 *
 * Every screen means each role's list screens, forms, and one of each detail
 * and edit screen, found from the links on its list. The checklist form
 * needs a check-in, so inspections.spec.js checks it, in its own flow.
 */

const screens = {
    admin: [
        '/admin/monitor', '/admin/lab-pcs', '/admin/aps', '/admin/devices', '/admin/agents', '/admin/locations',
        '/admin/users', '/admin/audit', '/admin/roster', '/admin/shift-templates', '/admin/checklists', '/admin/reports',
        '/admin/lab-pcs/create', '/admin/aps/create', '/admin/devices/create', '/admin/agents/create', '/admin/locations/create',
        '/admin/checklists/create', '/admin/shift-templates/create', '/admin/users/create', '/admin/incidents', '/notifications', '/profile',
    ],
    engineer: [
        '/engineer/dashboard', '/engineer/monitor', '/engineer/aps', '/engineer/traffic', '/engineer/tickets',
        '/engineer/risk', '/engineer/incidents', '/admin/reports', '/notifications', '/profile',
    ],
    assistant: [
        '/assistant/shifts/today', '/assistant/shifts', '/assistant/inspections', '/assistant/tickets',
        '/assistant/tickets/create', '/assistant/aps', '/assistant/monitor', '/assistant/incidents', '/notifications', '/profile',
    ],
};

/**
 * A list screen, and the link on it that opens one of its records. The demo
 * data fills every list, so a link that isn't there fails the test, unless
 * the entry is marked optional: incidents come and go with the simulator.
 */
const details = {
    admin: [
        ['/admin/users', /^\/admin\/users\/\d+\/edit$/],
        ['/admin/users', /^\/admin\/users\/\d+$/],
        ['/admin/lab-pcs', /^\/admin\/lab-pcs\/\d+$/],
        ['/admin/lab-pcs', /^\/admin\/lab-pcs\/\d+\/edit$/],
        ['/admin/aps', /^\/admin\/aps\/\d+$/],
        ['/admin/aps', /^\/admin\/aps\/\d+\/edit$/],
        ['/admin/devices', /^\/admin\/devices\/\d+$/],
        ['/admin/devices', /^\/admin\/devices\/\d+\/edit$/],
        ['/admin/agents', /^\/admin\/agents\/\d+$/],
        ['/admin/checklists', /^\/admin\/checklists\/\d+\/edit$/],
        ['/admin/locations', /^\/admin\/locations\/\d+\/edit$/],
        ['/admin/shift-templates', /^\/admin\/shift-templates\/\d+\/edit$/],
        ['/admin/incidents', /^\/admin\/incidents\/\d+$/],
        ['/admin/incidents?view=resolved', /^\/admin\/incidents\/\d+$/, 'optional'],
    ],
    engineer: [
        ['/engineer/tickets', /^\/engineer\/tickets\/\d+$/],
        ['/engineer/aps', /^\/engineer\/aps\/\d+$/],
        ['/engineer/risk', /^\/engineer\/risk\/[a-z-]+\/\d+$/],
        ['/engineer/incidents', /^\/engineer\/incidents\/\d+$/],
        ['/engineer/incidents?view=resolved', /^\/engineer\/incidents\/\d+$/, 'optional'],
    ],
    assistant: [
        ['/assistant/tickets', /^\/assistant\/tickets\/\d+$/],
        ['/assistant/inspections', /^\/assistant\/inspections\/create\?location=\d+$/],
        ['/assistant/inspections', /^\/inspections\/\d+$/],
        ['/assistant/incidents', /^\/assistant\/incidents\/\d+$/],
        ['/assistant/incidents?view=resolved', /^\/assistant\/incidents\/\d+$/, 'optional'],
    ],
};

/**
 * The list screens, then the first record each list links to. A list with
 * nothing in it, such as incidents on a quiet network, adds no detail page.
 */
async function everyScreen(page, role) {
    const found = [...screens[role]];

    for (const [list, link, optional] of details[role]) {
        await page.goto(list);
        const hrefs = await page.$$eval('a[href]', (anchors) => anchors.map((a) => new URL(a.href).pathname + new URL(a.href).search));
        const detail = hrefs.find((href) => link.test(href));

        if (detail) {
            found.push(detail);
        } else if (!optional) {
            throw new Error(`${list} has no link matching ${link}, so that screen would go unchecked.`);
        }
    }

    return [...new Set(found)];
}

/** axe's WCAG 2.1 A and AA rules on the page as it is. */
async function axeViolations(page, label) {
    const results = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']).analyze();

    return results.violations.map((v) => `${label}: ${v.id} (${v.impact}): ${v.nodes.slice(0, 3).map((n) => n.target.join(' ')).join(', ')}`
        + (v.nodes.length > 3 ? ` and ${v.nodes.length - 3} more` : ''));
}

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

test('the sign-in and forgot-password screens pass axe in both themes', async ({ page }) => {
    const found = [];

    for (const colorScheme of ['light', 'dark']) {
        await page.emulateMedia({ colorScheme });

        for (const path of ['/login', '/forgot-password']) {
            await page.goto(path);
            found.push(...await axeViolations(page, `${colorScheme} ${path}`));
        }
    }

    expect(found).toEqual([]);
});

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

test('the resolve dialog passes axe, and opens again with focus after a refused note', async ({ signedIn }) => {
    const page = await signedIn('engineer');
    await page.goto('/engineer/incidents');
    await page.getByRole('link', { name: /SW-BROWSER is degraded/ }).click();

    // The page's own button; the dialog's submit has the same name.
    const opener = page.getByRole('button', { name: 'Resolve' }).first();
    await opener.click();
    const note = page.getByLabel(/Why it can be closed/);
    await expect(note).toBeFocused();
    await withoutTransitions(page);
    expect(await axeViolations(page, 'resolve dialog')).toEqual([]);

    // Closed with Escape, focus goes back to the button that opened it.
    await page.keyboard.press('Escape');
    await expect(opener).toBeFocused();
    await opener.click();
    await expect(note).toBeFocused();

    // Too long for the server, past the browser's own limit.
    await note.evaluate((el) => { el.removeAttribute('maxlength'); el.value = 'x'.repeat(501); });
    await page.getByRole('dialog').getByRole('button', { name: 'Resolve' }).click();

    // Back from the server: the dialog is open, the error shown, the field focused.
    await expect(page.getByRole('dialog')).toBeVisible();
    await expect(page.getByRole('dialog')).toContainText('500');
    await expect(page.getByLabel(/Why it can be closed/)).toBeFocused();
    expect(await page.evaluate(() => document.body.classList.contains('overflow-y-hidden'))).toBe(true);
});

for (const role of Object.keys(screens)) {
    test(`every ${role} screen fits a phone without sideways scrolling`, async ({ signedIn }) => {
        const page = await signedIn(role);
        await page.setViewportSize(phone);
        const wide = [];

        for (const path of await everyScreen(page, role)) {
            const response = await page.goto(path);
            expect(response.status(), path).toBe(200);
            const [scroll, client] = await page.evaluate(() => [document.documentElement.scrollWidth, document.documentElement.clientWidth]);
            if (scroll > client + 1) wide.push(`${path}: ${scroll}px wide in a ${client}px window`);
        }

        expect(wide).toEqual([]);
    });

    test(`every ${role} screen passes axe in both themes`, async ({ signedIn }) => {
        test.setTimeout(240_000);
        const page = await signedIn(role);
        const found = [];
        const all = await everyScreen(page, role);

        for (const colorScheme of ['light', 'dark']) {
            await page.emulateMedia({ colorScheme });

            for (const path of all) {
                await page.goto(path);
                await withoutTransitions(page);
                found.push(...await axeViolations(page, `${colorScheme} ${path}`));
            }
        }

        expect(found).toEqual([]);
    });

    test(`every ${role} screen can be worked by keyboard`, async ({ signedIn }) => {
        test.setTimeout(300_000);
        const page = await signedIn(role);
        const found = [];
        const all = await everyScreen(page, role);

        for (const viewport of [{ width: 1440, height: 900 }, phone]) {
            await page.setViewportSize(viewport);

            for (const path of all) {
                await page.goto(path);
                await withoutTransitions(page);
                for (const problem of await sweep(page)) found.push(`${viewport.width}px ${path}: ${problem}`);
            }
        }

        expect(found).toEqual([]);
    });
}
