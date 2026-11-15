import { AxeBuilder } from '@axe-core/playwright';
import { expect, test } from '@playwright/test';

const THEMES = ['decree', 'broadsheet', 'quiet', 'almanac', 'terminal', 'zine', 'blueprint'];

// The admin is signed in, so ?theme= previews each theme without applying it.
// The error page is missing here on purpose: tests run in development mode, where
// Slim's own detailed error page appears instead of the themed one.
for (const colorScheme of ['light', 'dark']) {
    test.describe(`in ${colorScheme}`, () => {
        test.use({ colorScheme });

        for (const theme of THEMES) {
            test(`${theme} meets WCAG AA on the pages readers see`, async ({ page }) => {
                const problems = [];
                const check = async (path) => {
                    await page.goto(path);
                    await expect(page.locator('body')).toHaveClass(`theme-${theme}`);
                    await page.evaluate(() => document.fonts.ready);
                    const result = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa']).analyze();
                    problems.push(...result.violations.flatMap((violation) => violation.nodes.map(
                        (node) => `${path} ${violation.id}: ${node.target.join(' ')} ${node.any.map((check) => check.message).join('; ')}`,
                    )));
                };

                // The home page, then an article with whatever the earlier tests left on it.
                await check(`/?theme=${theme}`);
                const first = page.locator('.articles h2 a').first();
                const article = await first.getAttribute('href');
                // Its longest word: a short one may be a stop word, which the search ignores.
                const word = ((await first.textContent()) ?? '').split(/[^\p{L}\p{N}]+/u).sort((a, b) => b.length - a.length)[0] ?? '';
                await check(`${article}?theme=${theme}`);

                // A list with its pager, a search with results, and the sign-in page.
                await check(`/archive?theme=${theme}`);
                await check(`/search?q=${encodeURIComponent(word)}&theme=${theme}`);
                await expect(page.locator('.search-results li').first()).toBeVisible();
                await check(`/login?theme=${theme}`);

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
