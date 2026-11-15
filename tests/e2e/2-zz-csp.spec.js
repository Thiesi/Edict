import { expect, test } from '@playwright/test';

// The policy is strict, so anything inline or from elsewhere would be blocked silently.
// This visits the pages that do the most and fails on any violation, the themed 404 included.
test('no page breaks the Content-Security-Policy', async ({ page }) => {
    await page.addInitScript(() => {
        window.cspViolations = [];
        document.addEventListener('securitypolicyviolation', (event) => {
            window.cspViolations.push(`${event.documentURI} ${event.violatedDirective} ${event.blockedURI} ${event.sourceFile}:${event.lineNumber} ${event.sample}`);
        });
    });
    const violations = [];
    const collect = async () => {
        violations.push(...await page.evaluate(() => window.cspViolations ?? []));
    };

    for (const path of ['/', '/archive', '/search?q=a', '/login', '/admin', '/admin/articles', '/admin/media-library', '/admin/site', '/admin/comments', '/admin/tags', '/admin/themes', '/no-such-article']) {
        await page.goto(path);
        await page.waitForLoadState('load');
        await collect();
    }

    // Structured data is a data block, which browsers never run, so script-src doesn't
    // apply to it: these pages carry one, and the policy has nothing to report about them.
    const structuredData = async (type) => {
        const blocks = await page.locator('head script[type="application/ld+json"]').allTextContents();
        expect(blocks).toHaveLength(1);
        expect(JSON.parse(blocks[0])['@type']).toBe(type);
    };

    // An article with whatever the earlier tests left on it: pictures, players, likes, comments.
    await page.goto('/');
    await structuredData('WebSite');
    const href = await page.locator('.articles h2 a').first().getAttribute('href');
    await page.goto(href);
    await page.waitForLoadState('load');
    await structuredData('BlogPosting');
    await collect();

    // The editor, with its bundle, its bubble menu and the media library open.
    await page.goto('/admin/articles');
    await page.getByRole('button', { name: 'New article' }).click();
    await expect(page.locator('.ProseMirror')).toBeVisible();
    await page.locator('#media-toggle').click();
    await expect(page.locator('#media-panel button[data-media-id]').first()).toBeVisible();
    await page.locator('#media-panel button[data-media-id]:has(img):not(.is-video)').first().click();
    await page.locator('#media-panel').getByRole('button', { name: 'Insert' }).click();
    await page.locator('.ProseMirror').click();
    await page.keyboard.type('Some words to select');
    await page.keyboard.press('Shift+Home');
    await collect();

    expect(violations).toEqual([]);
});
