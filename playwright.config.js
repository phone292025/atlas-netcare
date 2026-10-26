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
