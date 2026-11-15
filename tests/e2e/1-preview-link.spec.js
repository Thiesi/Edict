import { AxeBuilder } from '@axe-core/playwright';
import { expect, test } from '@playwright/test';

// The editor's "Share preview": a private link to the draft, for someone without an account.
test('shares a draft by link, copies it, replaces it and stops sharing', async ({ page, browser, context, baseURL }) => {
    const problems = [];
    page.on('pageerror', (error) => problems.push(error.message));
    await context.grantPermissions(['clipboard-read', 'clipboard-write'], { origin: baseURL });

    await page.goto('/admin/articles');
    await page.getByRole('button', { name: 'New article' }).click();
    await expect(page.locator('.ProseMirror')).toBeVisible();
    await page.getByLabel('Title').fill('Shared draft');
    await page.locator('.ProseMirror').click();
    await page.keyboard.type('Words for one reader');
    await expect(page.locator('#save-status')).toContainText('Saved', { timeout: 15_000 });

    await page.locator('#details > summary').click();
    const share = page.locator('.editor-share');
    const status = page.locator('#share-status');
    const url = page.getByLabel('Preview link');
    await expect(url).toBeHidden();
    await expect(share.getByRole('button', { name: 'Stop sharing' })).toBeHidden();

    await share.getByRole('button', { name: 'Share preview' }).click();
    await expect(url).toBeVisible();
    await expect(url).toHaveValue(/^http:\/\/localhost:8080\/preview\/[A-Za-z0-9_-]{43}$/);
    await expect(status).toContainText(/Link made\. It works until \d{1,2} \w{3} \d{4}, \d{2}:\d{2} \(Berlin time\)\./);
    const first = await url.inputValue();

    await share.getByRole('button', { name: 'Copy' }).click();
    await expect(page.locator('#share-copied')).toHaveText('Copied.');
    expect(await page.evaluate(() => navigator.clipboard.readText())).toBe(first);

    // The control itself meets WCAG AA, link shown.
    const axe = await new AxeBuilder({ page }).include('.editor-share').withTags(['wcag2a', 'wcag2aa']).analyze();
    expect(axe.violations.map((violation) => violation.id)).toEqual([]);

    // A reader without an account sees the draft, marked as a preview, and later edits too.
    const reader = await browser.newContext({ storageState: { cookies: [], origins: [] } });
    const readerPage = await reader.newPage();
    await readerPage.addInitScript(() => {
        window.cspViolations = [];
        document.addEventListener('securitypolicyviolation', (event) => window.cspViolations.push(event.violatedDirective));
    });
    const opened = await readerPage.goto(first);
    expect(opened.status()).toBe(200);
    expect(opened.headers()['referrer-policy']).toBe('no-referrer');
    expect(opened.headers()['x-robots-tag']).toBe('noindex');
    await expect(readerPage.locator('.preview-banner')).toContainText('Preview, not published.');
    await expect(readerPage.locator('article h1')).toHaveText('Shared draft');
    await expect(readerPage.locator('article')).toContainText('Words for one reader');
    await expect(readerPage.locator('#likes, #comments')).toHaveCount(0);
    expect(await readerPage.evaluate(() => window.cspViolations)).toEqual([]);

    await page.locator('.ProseMirror').click();
    await page.keyboard.press('Control+End');
    await page.keyboard.type(', and later words');
    await expect(page.locator('#save-status')).toContainText('Saved', { timeout: 15_000 });
    await readerPage.reload();
    await expect(readerPage.locator('article')).toContainText('and later words');

    // After a reload, the editor says a link is out, but can't show it again.
    await page.reload();
    await page.locator('#details > summary').click();
    await expect(status).toContainText('A link is shared until');
    await expect(url).toBeHidden();

    // A new link: the old one stops working.
    await share.getByRole('button', { name: 'New link' }).click();
    await expect(url).toBeVisible();
    const second = await url.inputValue();
    expect(second).not.toBe(first);
    expect((await readerPage.goto(first)).status()).toBe(404);
    await expect(readerPage.locator('h1')).toHaveText('Not found');
    expect((await readerPage.goto(second)).status()).toBe(200);

    // Stopped: gone, and the control is back to its start.
    await share.getByRole('button', { name: 'Stop sharing' }).click();
    await expect(status).toHaveText('Sharing stopped. The link no longer works.');
    await expect(url).toBeHidden();
    await expect(share.getByRole('button', { name: 'Share preview' })).toBeFocused();
    expect((await readerPage.goto(second)).status()).toBe(404);

    await reader.close();
    expect(problems).toEqual([]);
});
