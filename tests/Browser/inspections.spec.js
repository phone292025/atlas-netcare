import { AxeBuilder } from '@axe-core/playwright';
import { expect, test } from './fixtures.js';

/**
 * Flow 3: an assistant checks in, inspects the next machine in a room's
 * round and submits the checklist.
 */

test('an assistant inspects the next machine in a round', async ({ signedIn }, testInfo) => {
    const assistant = await signedIn('assistant');

    // FR-203: an inspection happens during a shift, so check in first.
    // global-setup gave the assistant a shift that runs all day. The flows
    // run once per browser on one stack, so the second browser finds the
    // assistant checked in by the first; the first must see the prompt.
    await assistant.goto('/assistant/inspections');
    const prompt = assistant.getByText('Check in to start inspecting');
    if (testInfo.project.name === 'flows' || await prompt.count() > 0) {
        await expect(prompt).toBeVisible();
        await assistant.getByRole('link', { name: 'Go to My shift' }).click();
        await assistant.getByRole('button', { name: 'Check in' }).click();
        await expect(assistant.getByRole('button', { name: 'Check out' })).toBeVisible();
    }

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

    // The checklist form, the most-used screen, against axe's WCAG 2.1 AA
    // rules in both themes (ADR-0018). Here because it needs a check-in.
    for (const colorScheme of ['light', 'dark']) {
        // Reloaded, so the theme is the one the page starts in, not one
        // fading in: axe reads colours as they are at that instant.
        await assistant.emulateMedia({ colorScheme });
        await assistant.reload();
        await assistant.addStyleTag({ content: '*, *::before, *::after { transition: none !important; animation: none !important; }' });
        const { violations } = await new AxeBuilder({ page: assistant }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']).analyze();
        expect(violations.map((v) => `${colorScheme}: ${v.id} on ${v.nodes.map((n) => n.target.join(' ')).slice(0, 3).join(', ')}`)).toEqual([]);
    }
    await assistant.emulateMedia({ colorScheme: 'light' });
    await assistant.reload();

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
