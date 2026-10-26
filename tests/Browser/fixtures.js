import { test as base, expect } from '@playwright/test';

/** The accounts global-setup.js created, by role. */
export const accounts = JSON.parse(process.env.ATLAS_BROWSER_ACCOUNTS ?? '{}');

/** Where sign-in.spec.js saves each role's session for the other tests. */
export const sessionFile = (role) => `tests/Browser/.auth/${role}.json`;

/**
 * Collects what the page reports as broken: JavaScript errors, and failed
 * requests, which Chromium logs to the console as errors. A feature test can't
 * see either, and both leave a page looking almost right.
 */
function watch(page) {
    const problems = [];

    page.on('console', (message) => {
        if (message.type() === 'error') problems.push(message.text());
    });
    page.on('pageerror', (error) => problems.push(error.message));

    return problems;
}

export const test = base.extend({
    /** The plain page, for signing in, failing on console errors. */
    page: async ({ page }, use) => {
        const problems = watch(page);
        await use(page);
        expect(problems, 'errors in the browser console').toEqual([]);
    },

    /**
     * Opens a page signed in as a role, in a browser context of its own, so
     * one test can have the assistant and the engineer side by side:
     *
     *     const assistant = await signedIn('assistant');
     */
    signedIn: async ({ browser, baseURL, viewport }, use) => {
        const opened = [];

        await use(async (role) => {
            const context = await browser.newContext({ baseURL, viewport, storageState: sessionFile(role) });
            const page = await context.newPage();
            opened.push({ role, context, problems: watch(page) });

            return page;
        });

        for (const { role, context, problems } of opened) {
            await context.close();
            expect(problems, `errors in the ${role}'s browser console`).toEqual([]);
        }
    },
});

export { expect };
