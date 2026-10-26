import { expect, test } from './fixtures.js';

/**
 * Flow 5: an administrator prints the Reports page. On paper it is a document
 * of its own: a title block instead of the screen header, no button, and the
 * light palette even when the screen is dark (a007425).
 */

/** Relative luminance of a CSS rgb() colour, from 0 for black to 1 for white. */
function luminance(colour) {
    const [r, g, b] = colour.match(/[\d.]+/g).slice(0, 3).map((value) => {
        const channel = Number(value) / 255;
        return channel <= 0.03928 ? channel / 12.92 : ((channel + 0.055) / 1.055) ** 2.4;
    });

    return 0.2126 * r + 0.7152 * g + 0.0722 * b;
}

const textColour = (page) => page.evaluate(() => getComputedStyle(document.body).color);

test('an administrator prints the report', async ({ signedIn }) => {
    const admin = await signedIn('admin');
    await admin.emulateMedia({ colorScheme: 'dark' });
    await admin.goto('/admin/reports');

    const button = admin.getByRole('button', { name: 'Print or save as PDF' });
    const screenHeading = admin.getByRole('heading', { name: 'Reports', level: 2 });
    const titleBlock = admin.getByRole('heading', { name: 'Operations report', level: 1 });
    await expect(screenHeading).toBeVisible();
    await expect(titleBlock).toBeHidden();

    // Without this, the check on paper below could pass on a page that was
    // never dark at all.
    expect(luminance(await textColour(admin))).toBeGreaterThan(0.5);

    // Headless Chromium has no print dialog, so count the call instead.
    await admin.evaluate(() => {
        window.print = () => { window.printCalls = (window.printCalls ?? 0) + 1; };
    });
    await button.click();
    expect(await admin.evaluate(() => window.printCalls)).toBe(1);

    await admin.emulateMedia({ media: 'print', colorScheme: 'dark' });
    await expect(button).toBeHidden();
    await expect(screenHeading).toBeHidden();
    await expect(titleBlock).toBeVisible();
    await expect(admin.getByText(`Prepared by`)).toBeVisible();
    expect(luminance(await textColour(admin))).toBeLessThan(0.1);

    // Chromium lays the page out on A4, as "Save as PDF" would.
    const pdf = (await admin.pdf({ format: 'A4' })).toString('latin1');
    expect(pdf.match(/\/Type\s*\/Page\b/g)?.length ?? 0).toBeGreaterThan(0);
});
