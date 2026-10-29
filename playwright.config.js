import { defineConfig, devices } from '@playwright/test';
import { stack } from './tests/Browser/stack.js';

/**
 * Browser tests of the core flows, against a Docker stack of their own
 * (ADR-0015). Run them with `npm run test:browser`.
 *
 * The `sign-in` project signs each role in through the form and saves the
 * session. Everything else depends on it and starts signed in.
 */
export default defineConfig({
    testDir: 'tests/Browser',
    globalSetup: './tests/Browser/global-setup.js',
    globalTeardown: './tests/Browser/global-teardown.js',
    outputDir: 'test-results/browser',

    // A test that passes on its second try is a flaky test, and a retry hides it.
    retries: 0,

    // One at a time. The tests reuse one saved session per role, and two
    // running at once as the same person share a session: a request from
    // one used up or overwrote the other's flash message, so "APU-1002
    // passed." never showed (CI, 2026-10-29). Ten tests take about a minute
    // either way, most of it starting the stack.
    workers: 1,
    forbidOnly: !!process.env.CI,
    reporter: process.env.CI ? [['list'], ['html', { open: 'never' }]] : 'list',

    use: {
        ...devices['Desktop Chrome'],
        baseURL: stack.url,
        viewport: { width: 1440, height: 900 },
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
    },

    projects: [
        { name: 'sign-in', testMatch: 'sign-in.spec.js' },
        { name: 'flows', testIgnore: 'sign-in.spec.js', dependencies: ['sign-in'] },
    ],
});
