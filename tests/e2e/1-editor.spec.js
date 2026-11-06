import { expect, test } from '@playwright/test';

const newItem = async (page, kind = 'article') => {
    await page.goto(kind === 'page' ? '/admin/articles?kind=page' : '/admin/articles');
    await page.getByRole('button', { name: `New ${kind}` }).click();
    await expect(page).toHaveURL(/\/admin\/articles\/\d+$/);
    await expect(page.locator('.ProseMirror')).toBeVisible();
};

const saved = (page) => expect(page.locator('#save-status')).toContainText('Saved', { timeout: 15_000 });

const publish = async (page) => {
    await page.locator('#publish').click();
    await expect(page.getByRole('link', { name: 'View' })).toBeVisible();
    // The link shows while the reloaded page is still loading its script.
    await page.waitForLoadState('load');
};

test('writes, autosaves, survives a reload and publishes', async ({ page, request }) => {
    const problems = [];
    page.on('pageerror', (error) => problems.push(error.message));

    await newItem(page);
    await page.getByLabel('Title').fill('Über Größe');
    await page.locator('.ProseMirror').click();
    await page.keyboard.type('## A shortcut heading');
    await page.keyboard.press('Enter');
    await page.keyboard.type('Plain then ');
    await page.keyboard.press('Control+B');
    await page.keyboard.type('bold');
    await page.keyboard.press('Control+B');
    await saved(page);

    await page.reload();
    await expect(page.getByLabel('Title')).toHaveValue('Über Größe');
    await expect(page.locator('.ProseMirror h2')).toHaveText('A shortcut heading');
    await expect(page.locator('.ProseMirror strong')).toHaveText('bold');

    expect((await request.get('/ueber-groesse')).status()).toBe(404);

    await publish(page);

    await page.goto('/ueber-groesse');
    await expect(page.locator('article h1')).toHaveText('Über Größe');
    await expect(page.locator('article h2')).toHaveText('A shortcut heading');
    await expect(page.locator('article strong')).toHaveText('bold');

    expect(problems).toEqual([]);
});

test('editing a published article stays private until Update', async ({ page, request, context }) => {
    await newItem(page);
    await page.getByLabel('Title').fill('Published one');
    await page.locator('.ProseMirror').click();
    await page.keyboard.type('Original words');
    await saved(page);
    await publish(page);

    await page.locator('.ProseMirror').click();
    await page.keyboard.press('Control+End');
    await page.keyboard.type(' plus private words');
    await saved(page);

    expect(await (await request.get('/published-one')).text()).not.toContain('private words');

    const [preview] = await Promise.all([context.waitForEvent('page'), page.locator('#preview').click()]);
    await expect(preview.locator('article')).toContainText('private words');
    await preview.close();

    await page.locator('#publish').click();
    await expect(page.locator('#save-status')).toBeVisible();
    await expect.poll(async () => (await request.get('/published-one')).text()).toContain('private words');
});

test('the slash menu inserts blocks', async ({ page }) => {
    await newItem(page);
    await page.locator('.ProseMirror').click();

    await page.keyboard.type('/quo');
    await expect(page.locator('.slash-menu')).toContainText('Quote');
    await page.keyboard.press('Enter');
    await page.keyboard.type('Quoted');
    await expect(page.locator('.ProseMirror blockquote')).toHaveText('Quoted');

    await page.keyboard.press('Enter');
    await page.keyboard.press('Enter');
    await page.keyboard.type('/table');
    await page.keyboard.press('Enter');
    await expect(page.locator('.ProseMirror table')).toBeVisible();
    await expect(page.locator('#table-tools')).toBeVisible();

    await saved(page);
    await page.reload();
    await expect(page.locator('.ProseMirror blockquote')).toHaveText('Quoted');
    await expect(page.locator('.ProseMirror table')).toBeVisible();
});

test('the toolbar formats a selection', async ({ page }) => {
    await newItem(page);
    await page.locator('.ProseMirror').click();
    await page.keyboard.type('Format me');
    await page.keyboard.press('Control+A');

    await page.locator('#bubble-menu [data-command="italic"]').click();

    await expect(page.locator('.ProseMirror em')).toHaveText('Format me');
});

test('opening an article changes nothing', async ({ page, request }) => {
    await newItem(page);
    await page.getByLabel('Title').fill('Just looking');
    await page.locator('.ProseMirror').click();
    await page.keyboard.type('## Ends in a heading');
    await saved(page);

    let saves = 0;
    page.on('request', (sent) => {
        if (sent.url().endsWith('/autosave')) {
            saves += 1;
        }
    });
    await page.reload();
    await page.locator('.ProseMirror').click();
    await page.waitForTimeout(4000);

    expect(saves).toBe(0);
    await expect(page.locator('#save-status')).toHaveText('Up to date');
});

test('warns before leaving with unsaved text', async ({ page }) => {
    await newItem(page);
    const prevented = () => page.evaluate(() => {
        const event = new Event('beforeunload', { cancelable: true });
        window.dispatchEvent(event);

        return event.defaultPrevented;
    });

    expect(await prevented()).toBe(false);

    await page.locator('.ProseMirror').click();
    await page.keyboard.type('Not saved yet');
    expect(await prevented()).toBe(true);

    await saved(page);
    expect(await prevented()).toBe(false);
});

test('offers text that never reached the server', async ({ page }) => {
    await newItem(page);
    await page.route('**/autosave', (route) => route.abort());
    page.on('dialog', (dialog) => dialog.accept());
    await page.locator('.ProseMirror').click();
    await page.keyboard.type('Rescue me');
    await expect(page.locator('#save-status')).toContainText('Not saved', { timeout: 15_000 });

    await page.unroute('**/autosave');
    await page.reload();

    await expect(page.locator('#restore-banner')).toBeVisible();
    await expect(page.locator('.ProseMirror')).not.toContainText('Rescue me');
    await page.locator('#restore-yes').click();
    await expect(page.locator('.ProseMirror')).toContainText('Rescue me');
    await saved(page);

    await page.reload();
    await expect(page.locator('#restore-banner')).toBeHidden();
    await expect(page.locator('.ProseMirror')).toContainText('Rescue me');
});

test('an offered backup stays until answered, whatever else happens', async ({ page }) => {
    await newItem(page);
    await page.route('**/autosave', (route) => route.abort());
    page.on('dialog', (dialog) => dialog.accept());
    await page.locator('.ProseMirror').click();
    await page.keyboard.type('Precious words');
    await expect(page.locator('#save-status')).toContainText('Not saved', { timeout: 15_000 });
    await page.unroute('**/autosave');
    await page.reload();

    // Ignore the offer, write something else, and let it save.
    await expect(page.locator('#restore-banner')).toBeVisible();
    await page.locator('.ProseMirror').click();
    await page.keyboard.type('Different words');
    await saved(page);
    await page.reload();

    await expect(page.locator('#restore-banner')).toBeVisible();
    await expect(page.locator('#restore-message')).toContainText('has changed since');
    await page.locator('#restore-banner summary').click();
    await expect(page.locator('#restore-text')).toContainText('Precious words');

    await page.locator('#restore-no').click();
    await expect(page.locator('#restore-banner')).toBeHidden();
    await expect(page.locator('.ProseMirror')).toContainText('Different words');
});

test('a lost answer does not stop autosave', async ({ page }) => {
    await newItem(page);
    await page.locator('.ProseMirror').click();
    await page.keyboard.type('First');

    // The server commits, but the browser never hears about it.
    let lost = false;
    await page.route('**/autosave', async (route) => {
        if (lost) {
            await route.continue();

            return;
        }
        lost = true;
        await route.fetch();
        await route.abort();
    });
    await expect(page.locator('#save-status')).toContainText('Retrying', { timeout: 15_000 });
    await saved(page);

    await page.keyboard.type(' second');
    await saved(page);
    await page.reload();
    await expect(page.locator('.ProseMirror')).toContainText('First second');
});

test('a second tab cannot silently overwrite newer text', async ({ page, context }) => {
    await newItem(page);
    const second = await context.newPage();
    await second.goto(page.url());

    await page.locator('.ProseMirror').click();
    await page.keyboard.type('From the first tab');
    await saved(page);

    await second.locator('.ProseMirror').click();
    await second.keyboard.type('From the stale tab');
    await expect(second.locator('#save-status')).toContainText('another tab', { timeout: 15_000 });
    await expect(second.locator('.ProseMirror')).toContainText('From the stale tab');

    await page.reload();
    await expect(page.locator('.ProseMirror')).toContainText('From the first tab');
    await expect(page.locator('.ProseMirror')).not.toContainText('From the stale tab');
    await second.close();
});

test('publishing reports what is missing', async ({ page }) => {
    await newItem(page);

    await page.locator('#publish').click();

    await expect(page.locator('#editor-errors')).toContainText('title');
    await expect(page.locator('#publish')).toHaveText('Publish');
});

test('a static page is reachable but not listed', async ({ page, request }) => {
    await newItem(page, 'page');
    await page.getByLabel('Title').fill('Impressum');
    await page.locator('.ProseMirror').click();
    await page.keyboard.type('Angaben');
    await saved(page);
    await publish(page);

    expect(await (await request.get('/impressum')).text()).toContain('Angaben');
    expect(await (await request.get('/archive')).text()).not.toContain('Impressum');
});
