import { expect, test } from '@playwright/test';

// The admin is signed in here, and likes like any reader, plus the adjustment controls.
test('a like counts without reloading, and the sovereign adjusts the count', async ({ page }) => {
    const problems = [];
    page.on('pageerror', (error) => problems.push(error.message));

    await page.goto('/admin/articles');
    await page.getByRole('button', { name: 'New article' }).click();
    await expect(page.locator('.ProseMirror')).toBeVisible();
    await page.getByLabel('Title').fill('Worth a like');
    await expect(page.locator('#save-status')).toContainText('Saved', { timeout: 15_000 });
    await page.locator('#publish').click();
    await expect(page.getByRole('link', { name: 'View', exact: true })).toBeVisible();
    await page.waitForLoadState('load');

    await page.goto('/worth-a-like');
    const likes = page.locator('#likes');
    const button = likes.getByRole('button', { name: 'Like' });
    await expect(button).toHaveAttribute('aria-pressed', 'false');
    await expect(likes.locator('.like-count')).toHaveText('0 likes');

    let reloaded = false;
    page.on('framenavigated', () => {
        reloaded = true;
    });
    await button.click();
    await expect(button).toHaveAttribute('aria-pressed', 'true');
    await expect(likes.locator('.like-count')).toHaveText('1 like');
    expect(reloaded, 'the script did it in place').toBe(false);

    await likes.getByRole('button', { name: '+100' }).click();
    await expect(likes.locator('.like-count')).toHaveText('101 likes');
    await expect(likes).toContainText('Real likes: 1 · adjustment: +100');

    await likes.getByLabel('Adjustment').fill('-5');
    await likes.getByRole('button', { name: 'Set' }).click();
    await expect(likes.locator('.like-count')).toHaveText('0 likes');

    await button.click();
    await expect(button).toHaveAttribute('aria-pressed', 'false');
    expect(problems).toEqual([]);
});
