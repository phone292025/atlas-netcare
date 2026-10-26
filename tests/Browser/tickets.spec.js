import { expect, test } from './fixtures.js';

/**
 * Flow 2: an assistant raises a ticket about one machine, picked from the room
 * plan, and an engineer takes it through to resolved.
 */

const room = 'L6-IT-01 · Level 6 IT Lab 01';

test('an assistant raises a ticket and an engineer resolves it', async ({ signedIn }) => {
    // Unique, so the queue row is this run's ticket and no other.
    const title = `Monitor flickers ${Date.now()}`;

    const assistant = await signedIn('assistant');
    await assistant.goto('/assistant/tickets/create');
    await assistant.getByRole('textbox', { name: 'What is wrong?' }).fill(title);
    await assistant.getByRole('textbox', { name: 'Describe it' }).fill('The picture flickers every few seconds. Reseating the cable changed nothing.');
    await assistant.getByText('Hardware', { exact: true }).click();
    await assistant.getByRole('combobox', { name: 'Where?' }).selectOption({ label: room });

    // The plan is a quicker way to pick than the select, and hidden from
    // screen readers, which use the select. A desk's title is its asset tag,
    // with the open ticket added when it has one: this picks a machine without.
    const desk = assistant.getByTitle(/^APU-\d+$/).first();
    const tag = (await desk.textContent()).trim();
    await desk.click();
    await expect(assistant.getByRole('combobox', { name: 'Which machine?' }).locator('option:checked')).toHaveText(tag);

    await assistant.getByRole('button', { name: 'Raise ticket' }).click();

    await expect(assistant).toHaveURL(/\/assistant\/tickets\/\d+$/);
    await expect(assistant.getByRole('heading', { name: title })).toBeVisible();
    const number = (await assistant.getByText(/^TKT-\d{4}-\d{5}$/).first().textContent()).trim();

    const engineer = await signedIn('engineer');
    await engineer.goto('/engineer/tickets');
    await engineer.getByRole('row', { name: title }).getByRole('link', { name: number }).click();

    await engineer.getByRole('button', { name: 'Acknowledge' }).click();
    await expect(engineer.getByRole('status')).toContainText(`${number} is now Acknowledged.`);

    await engineer.getByRole('button', { name: 'Start work' }).click();
    await expect(engineer.getByRole('status')).toContainText(`${number} is now In progress.`);

    await engineer.getByRole('button', { name: 'Resolve' }).click();
    const resolve = engineer.getByRole('dialog', { name: `Resolve ${number}` });
    await resolve.getByRole('textbox', { name: 'What did you do to fix it?' }).fill('Replaced the DisplayPort cable.');
    await resolve.getByRole('button', { name: 'Resolve ticket' }).click();
    await expect(engineer.getByRole('status')).toContainText(`${number} is now Resolved.`);

    // The person who reported it sees the outcome.
    await assistant.reload();
    await expect(assistant.getByRole('main').getByText('Resolved', { exact: true })).toBeVisible();
});

test('the room plan warns about a machine that already has an open ticket', async ({ signedIn }) => {
    const title = `Keyboard missing keys ${Date.now()}`;

    const assistant = await signedIn('assistant');
    await assistant.goto('/assistant/tickets/create');
    await assistant.getByRole('textbox', { name: 'What is wrong?' }).fill(title);
    await assistant.getByRole('textbox', { name: 'Describe it' }).fill('The F and J keys are gone.');
    await assistant.getByText('Peripheral', { exact: true }).click();
    await assistant.getByRole('combobox', { name: 'Where?' }).selectOption({ label: room });

    const desk = assistant.getByTitle(/^APU-\d+$/).last();
    const tag = (await desk.textContent()).trim();
    await desk.click();
    await assistant.getByRole('button', { name: 'Raise ticket' }).click();
    await expect(assistant.getByRole('heading', { name: title })).toBeVisible();

    // A second ticket for the same machine.
    await assistant.goto('/assistant/tickets/create');
    await assistant.getByRole('combobox', { name: 'Where?' }).selectOption({ label: room });
    await assistant.getByTitle(new RegExp(`^${tag}, open ticket `)).click();

    await expect(assistant.getByRole('alert')).toContainText(`${tag} already has an open ticket`);
    await expect(assistant.getByRole('alert')).toContainText(title);
});
