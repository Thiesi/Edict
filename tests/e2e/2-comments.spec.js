import { expect, test } from '@playwright/test';

// The admin comments like a reader, and sees the moderation controls beside each comment.
test('comments thread, and the sovereign moderates them in place', async ({ page }) => {
    await page.goto('/admin/articles');
    await page.getByRole('button', { name: 'New article' }).click();
    await expect(page.locator('.ProseMirror')).toBeVisible();
    await page.getByLabel('Title').fill('Up for discussion');
    await expect(page.locator('#save-status')).toContainText('Saved', { timeout: 15_000 });
    await page.locator('#publish').click();
    await expect(page.getByRole('link', { name: 'View', exact: true })).toBeVisible();
    await page.waitForLoadState('load');

    await page.goto('/up-for-discussion');
    const comments = page.locator('#comments');
    await expect(comments.getByRole('heading', { name: '0 comments' })).toBeVisible();

    await comments.getByLabel(/Your comment/).fill('First thoughts, see https://example.org');
    await comments.getByRole('button', { name: 'Post comment' }).click();
    await expect(page.getByRole('status')).toContainText('Thanks, your comment is saved.');
    const first = comments.locator('.comment').first();
    await expect(first.getByRole('link', { name: 'https://example.org' })).toHaveAttribute('rel', 'nofollow ugc noopener');

    await first.getByRole('link', { name: 'Reply' }).click();
    await page.getByLabel(/Your reply/).fill('Answering myself');
    await page.getByRole('button', { name: 'Reply', exact: true }).click();
    await expect(comments.getByRole('heading', { name: '2 comments' })).toBeVisible();
    await expect(first.locator('.comment-replies .comment')).toContainText('Answering myself');

    await first.locator('.comment-actions').first().getByRole('button', { name: 'Pin' }).click();
    await expect(comments.locator('.comment.is-pinned')).toHaveCount(1);

    await first.locator('.comment-actions').first().getByRole('button', { name: 'Remove only this' }).click();
    await expect(comments.locator('.comment').first()).toContainText('removed');
    await expect(comments).toContainText('Answering myself');
});
