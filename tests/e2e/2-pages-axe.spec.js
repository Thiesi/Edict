import { AxeBuilder } from '@axe-core/playwright';
import { expect, test } from '@playwright/test';

// The pages 2-themes doesn't reach: the admin, the editor, and the sign-in and account
// pages. The theme previews inside the picker are left out; 2-themes checks each theme.
const problemsOn = async (page) => {
    await page.evaluate(() => document.fonts.ready);
    const result = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa']).exclude('iframe').analyze();

    return result.violations.flatMap((violation) => violation.nodes.map(
        (node) => `${page.url()} ${violation.id}: ${node.target.join(' ')} ${node.any.map((check) => check.message).join('; ')}`,
    ));
};

const ADMIN_PAGES = [
    '/admin',
    '/admin/articles',
    '/admin/articles?kind=page',
    '/admin/articles?trash=1',
    '/admin/media-library',
    '/admin/media-library?show=unused&sort=size',
    '/admin/tags',
    '/admin/comments',
    '/admin/site',
    '/admin/themes',
    '/admin/passkeys',
    '/account',
];

for (const colorScheme of ['light', 'dark']) {
    test.describe(`in ${colorScheme}`, () => {
        test.use({ colorScheme });

        test('the admin pages and the account page meet WCAG AA', async ({ page }) => {
            const problems = [];
            for (const path of ADMIN_PAGES) {
                await page.goto(path);
                problems.push(...await problemsOn(page));
            }
            expect(problems).toEqual([]);
        });

        test('the editor meets WCAG AA, with the media library open', async ({ page }) => {
            // An article the earlier tests wrote, with whatever blocks they put in it.
            await page.goto('/admin/articles');
            await page.locator('table a[href^="/admin/articles/"]').first().click();
            await expect(page.locator('.ProseMirror')).toBeVisible();
            // The writing area is a text box, so it has a name.
            await expect(page.getByRole('textbox', { name: 'Text', exact: true })).toBeVisible();
            await page.locator('#media-toggle').click();
            await expect(page.locator('#media-panel')).toBeVisible();

            expect(await problemsOn(page)).toEqual([]);
        });

        test.describe('signed out', () => {
            test.use({ storageState: { cookies: [], origins: [] } });

            test('the sign-in pages meet WCAG AA', async ({ page }) => {
                const problems = [];
                for (const path of ['/login', '/admin/login']) {
                    await page.goto(path);
                    problems.push(...await problemsOn(page));
                }
                expect(problems).toEqual([]);
            });
        });
    });
}
