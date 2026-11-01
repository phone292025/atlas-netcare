import { expect, test } from './fixtures.js';

/**
 * Flow 3: an assistant checks in, inspects the next machine in a room's
 * round and submits the checklist.
 */

test('an assistant inspects the next machine in a round', async ({ signedIn }) => {
    const assistant = await signedIn('assistant');

    // FR-203: an inspection happens during a shift, so check in first.
    // global-setup gave the assistant a shift that runs all day.
    await assistant.goto('/assistant/inspections');
    await expect(assistant.getByText('Check in to start inspecting')).toBeVisible();
    await assistant.getByRole('link', { name: 'Go to My shift' }).click();
    await assistant.getByRole('button', { name: 'Check in' }).click();
    await expect(assistant.getByRole('button', { name: 'Check out' })).toBeVisible();

    await assistant.goto('/assistant/inspections');
    await expect(assistant.getByText('Check in to start inspecting')).toHaveCount(0);
    await assistant.getByRole('region', { name: 'Rounds today' }).getByRole('link', { name: /Level 5 Networking Lab/ }).click();
    await expect(assistant.getByRole('heading', { name: 'Round in Level 5 Networking Lab' })).toBeVisible();

    const progress = assistant.getByText(/^\d+ of \d+ checked today\.$/);
    const [, before, total] = (await progress.textContent()).match(/(\d+) of (\d+)/).map(Number);

    const start = assistant.getByRole('button', { name: /^Inspect APU-\d+$/ });
    const tag = (await start.textContent()).trim().replace('Inspect ', '');
    await start.click();
    await expect(assistant.getByRole('heading', { name: tag, level: 2 })).toBeVisible();

    // A section folds up once every question in it is answered, so its
    // questions leave the page. Answer the first open question until none is.
    const unanswered = assistant.getByRole('radiogroup').filter({ has: assistant.getByRole('radio', { name: 'OK', checked: false }) });
    for (let guard = 0; guard < 50 && (await unanswered.count()) > 0; guard++) {
        await unanswered.first().getByText('OK', { exact: true }).click();
    }
    await expect(assistant.getByText(/^All \d+ answered$/)).toBeVisible();

    await assistant.getByRole('button', { name: /^Save/ }).click();
    await expect(assistant.getByRole('status')).toContainText(`${tag} passed.`);

    await assistant.goto('/assistant/inspections');
    await expect(assistant.getByRole('link', { name: new RegExp(`^${tag} L5-NET-01 · .* Pass$`) })).toBeVisible();
    await expect(assistant.getByRole('region', { name: 'Rounds today' }).getByRole('link', { name: /Level 5 Networking Lab/ }))
        .toContainText(`${before + 1} of ${total} checked today`);
});
