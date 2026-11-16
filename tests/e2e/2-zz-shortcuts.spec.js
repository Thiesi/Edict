import { expect, test } from '@playwright/test';

// The admin starts writing from the live site: "New page" in the site's menu is a form
// that posts to the admin with the session's token, and lands in the editor. A page,
// so the articles list the earlier tests rely on stays as it was. Before 3-passkey,
// which signs out.
test('the site menu starts a new page in the editor', async ({ page }) => {
    const problems = [];
    page.on('pageerror', (error) => problems.push(error.message));

    await page.goto('/admin/articles?kind=page');
    const before = await page.locator('table.list tbody tr').count();

    await page.goto('/archive');
    const menu = page.getByRole('navigation', { name: 'Main' });
    await expect(menu.getByRole('button', { name: 'New article' })).toBeVisible();
    await menu.getByRole('button', { name: 'New page' }).click();
    await expect(page).toHaveURL(/\/admin\/articles\/[0-9]+$/);
    await expect(page.locator('.ProseMirror')).toBeVisible();

    await page.goto('/admin/articles?kind=page');
    await expect(page.locator('table.list tbody tr')).toHaveCount(before + 1);
    expect(problems).toEqual([]);
});
