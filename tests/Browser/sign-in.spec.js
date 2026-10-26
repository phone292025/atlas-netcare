import { randomUUID } from 'node:crypto';
import { accounts, expect, sessionFile, test } from './fixtures.js';

/**
 * Flow 1: every role signs in through the form and lands on its own home
 * screen. Each session is saved for the other tests, which start signed in.
 */

async function signIn(page, { email, password }) {
    await page.goto('/login');
    await page.getByRole('textbox', { name: 'Email' }).fill(email);
    await page.getByRole('textbox', { name: 'Password' }).fill(password);
    await page.getByRole('button', { name: 'Sign in' }).click();
}

const firstName = (account) => account.name.split(' ')[0];

test('a wrong password is turned away', async ({ page }) => {
    await signIn(page, { email: accounts.engineer.email, password: 'not the password' });

    await expect(page).toHaveURL(/\/login$/);
    await expect(page.getByText('These credentials do not match our records.')).toBeVisible();
});

test('an assistant lands on today\'s shift', async ({ page }) => {
    await signIn(page, accounts.assistant);

    await expect(page).toHaveURL(/\/assistant\/shifts\/today$/);
    await expect(page.getByRole('heading', { level: 2 }).first()).toHaveText(new RegExp(`, ${firstName(accounts.assistant)}$`));

    await page.context().storageState({ path: sessionFile('assistant') });
});

test('an engineer lands on the dashboard', async ({ page }) => {
    await signIn(page, accounts.engineer);

    await expect(page).toHaveURL(/\/engineer\/dashboard$/);
    await expect(page.getByRole('heading', { level: 2 }).first()).toHaveText(new RegExp(`, ${firstName(accounts.engineer)}$`));
    await expect(page.getByRole('heading', { name: 'Your queue' })).toBeVisible();

    await page.context().storageState({ path: sessionFile('engineer') });
});

test('the first administrator chooses a password before anything else', async ({ page }) => {
    // The password atlas:create-admin printed. Nothing opens until it's replaced.
    await signIn(page, accounts.admin);

    await expect(page).toHaveURL(/\/profile$/);
    await expect(page.getByRole('status')).toContainText('Choose your own before you carry on.');

    await page.goto('/admin/users');
    await expect(page).toHaveURL(/\/profile$/);

    const chosen = `Browser test ${randomUUID()}`;
    await page.getByRole('textbox', { name: 'Current password' }).fill(accounts.admin.password);
    await page.getByRole('textbox', { name: 'New password', exact: true }).fill(chosen);
    await page.getByRole('textbox', { name: 'Confirm new password' }).fill(chosen);
    await page.getByRole('button', { name: 'Change password' }).click();
    await expect(page.getByRole('status')).toContainText('Password changed.');

    await page.goto('/home');
    await expect(page).toHaveURL(/\/admin\/users$/);
    await expect(page.getByRole('cell', { name: accounts.admin.email })).toBeVisible();

    await page.context().storageState({ path: sessionFile('admin') });
});
