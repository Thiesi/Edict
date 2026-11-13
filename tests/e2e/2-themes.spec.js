import { AxeBuilder } from '@axe-core/playwright';
import { expect, test } from '@playwright/test';

const THEMES = ['decree', 'broadsheet', 'quiet', 'almanac', 'terminal', 'zine'];

// The admin is signed in, so ?theme= previews each theme without applying it.
for (const colorScheme of ['light', 'dark']) {
    test.describe(`in ${colorScheme}`, () => {
        test.use({ colorScheme });

        for (const theme of THEMES) {
            test(`${theme} meets WCAG AA on the home page and an article`, async ({ page }) => {
                await page.goto(`/?theme=${theme}`);
                await expect(page.locator('body')).toHaveClass(`theme-${theme}`);
                const article = await page.locator('.articles h2 a').first().getAttribute('href');
                await page.evaluate(() => document.fonts.ready);
                const home = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa']).analyze();

                await page.goto(`${article}?theme=${theme}`);
                await page.evaluate(() => document.fonts.ready);
                const reading = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa']).analyze();

                const problems = [...home.violations, ...reading.violations].flatMap((violation) => violation.nodes.map(
                    (node) => `${violation.id}: ${node.target.join(' ')} ${node.any.map((check) => check.message).join('; ')}`,
                ));
                expect(problems).toEqual([]);
            });
        }
    });
}

test('the theme picker shows every theme and applies one', async ({ page }) => {
    await page.goto('/admin/themes');
    await expect(page.locator('.theme-card iframe')).toHaveCount(THEMES.length);

    await page.getByRole('button', { name: 'Use Quiet' }).click();
    await expect(page.getByRole('status')).toContainText('Readers now see Quiet');
    await page.goto('/archive');
    await expect(page.locator('body')).toHaveClass('theme-quiet');

    // Back to the default, for the tests after this one.
    await page.goto('/admin/themes');
    await page.getByRole('button', { name: 'Use Decree' }).click();
    await expect(page.getByRole('status')).toContainText('Readers now see Decree');
});
