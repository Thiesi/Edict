import { expect, test } from '@playwright/test';
import { fillTags } from './helpers.js';

const newItem = async (page, kind = 'article') => {
    await page.goto(kind === 'page' ? '/admin/articles?kind=page' : '/admin/articles');
    await page.getByRole('button', { name: `New ${kind}` }).click();
    await expect(page).toHaveURL(/\/admin\/articles\/\d+$/);
    await expect(page.locator('.ProseMirror')).toBeVisible();
};

const saved = (page) => expect(page.locator('#save-status')).toContainText('Saved', { timeout: 15_000 });

const publish = async (page) => {
    await page.locator('#publish').click();
    // exact: otherwise this also matches "Preview", which is always there.
    await expect(page.getByRole('link', { name: 'View', exact: true })).toBeVisible();
    await page.waitForLoadState('load');
};

test('writes, autosaves, survives a reload and publishes', async ({ page, request }) => {
    const problems = [];
    page.on('pageerror', (error) => problems.push(error.message));

    await newItem(page);
    await page.getByLabel('Title').fill('Über Größe');
    // A shared tag, so the theme checks see previous, next and related articles (2-themes).
    await fillTags(page, 'Reading aids');
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
    await fillTags(page, 'Reading aids, Editing');
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
    // The menu belongs to a selection: none on load, none while typing.
    await expect(page.locator('#bubble-menu')).toBeHidden();
    await page.locator('.ProseMirror').click();
    await page.keyboard.type('Format me');
    await expect(page.locator('#bubble-menu')).toBeHidden();
    await page.keyboard.press('Control+A');
    await expect(page.locator('#bubble-menu')).toBeVisible();

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

test('the restore offer stays out of sight with nothing to restore', async ({ page }) => {
    await newItem(page);

    await expect(page.locator('#restore-banner')).toBeHidden();
    await expect(page.getByText('Show that text')).toBeHidden();
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

// A datetime-local value in Berlin time, which is what the server reads it as.
const berlinField = (date) => date.toLocaleString('sv-SE', { timeZone: 'Europe/Berlin' }).slice(0, 16).replace(' ', 'T');
// As the admin shows it: "15 Nov 2026, 18:00".
const berlinShown = (value) => {
    const [year, month, day, hour, minute] = value.split(/[-T:]/);
    const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

    return `${Number(day)} ${months[Number(month) - 1]} ${year}, ${hour}:${minute}`;
};
const daysAhead = (days) => {
    const date = new Date(Date.now() + days * 86_400_000);
    date.setUTCMinutes(0, 0, 0);

    return berlinField(date);
};

test('schedules, refuses the past, changes the time, cancels and publishes now', async ({ page, request }) => {
    const problems = [];
    page.on('pageerror', (error) => problems.push(error.message));

    await newItem(page);
    await page.getByLabel('Title').fill('Scheduled one');
    await fillTags(page, 'Reading aids');
    await page.locator('.ProseMirror').click();
    await page.keyboard.type('Words for later');
    await saved(page);

    const button = page.locator('#publish');
    const toggle = page.getByRole('button', { name: 'Schedule…' });
    const field = page.getByLabel('Publish at');
    await expect(button).toHaveText('Publish');
    await expect(field).toBeHidden();
    await toggle.click();
    await expect(toggle).toHaveAttribute('aria-expanded', 'true');
    await expect(field).toBeFocused();

    // A time sets the main button to Schedule; clearing it sets it back.
    await field.fill(daysAhead(2));
    await expect(button).toHaveText('Schedule');
    await page.getByRole('button', { name: 'Clear' }).click();
    await expect(button).toHaveText('Publish');

    // The past is refused, with a message, and nothing goes live.
    await field.fill(berlinField(new Date(Date.now() - 86_400_000)));
    await button.click();
    await expect(page.locator('#editor-errors')).toContainText('has passed');
    expect((await request.get('/scheduled-one')).status()).toBe(404);

    const first = daysAhead(2);
    await field.fill(first);
    await button.click();
    const banner = page.locator('#scheduled-banner');
    await expect(banner).toContainText(`Scheduled for ${berlinShown(first)}`);
    await expect(button).toHaveText('Schedule');
    await expect(page.getByRole('link', { name: 'View', exact: true })).toHaveCount(0);
    expect((await request.get('/scheduled-one')).status()).toBe(404);
    expect(await (await request.get('/')).text()).not.toContain('Scheduled one');
    expect(await (await request.get('/feed.atom')).text()).not.toContain('Scheduled one');

    // The list shows when, and offers to cancel.
    await page.goto('/admin/articles');
    const row = page.locator('tr', { hasText: 'Scheduled one' });
    await expect(row.locator('.pill-scheduled')).toHaveText('scheduled');
    await expect(row).toContainText(berlinShown(first));
    await expect(row.getByRole('button', { name: 'Cancel schedule' })).toBeVisible();
    await row.getByRole('link', { name: 'Scheduled one' }).click();

    // Another time.
    const second = daysAhead(3);
    await banner.getByRole('button', { name: 'Change time' }).click();
    await expect(field).toHaveValue(first);
    await field.fill(second);
    await button.click();
    await expect(banner).toContainText(`Scheduled for ${berlinShown(second)}`);

    // Cancelled: a draft again.
    await banner.getByRole('button', { name: 'Cancel schedule' }).click();
    await expect(banner).toHaveCount(0);
    await expect(button).toHaveText('Publish');
    await expect(toggle).toBeVisible();
    expect((await request.get('/scheduled-one')).status()).toBe(404);

    // Scheduled again, then published right away after all.
    await toggle.click();
    await field.fill(daysAhead(2));
    await button.click();
    await expect(banner).toBeVisible();
    await banner.getByRole('button', { name: 'Publish now' }).click();
    await expect(page.getByRole('link', { name: 'View', exact: true })).toBeVisible();
    await expect(button).toHaveText('Update');
    await expect(toggle).toHaveCount(0);
    expect(await (await request.get('/scheduled-one')).text()).toContain('Words for later');

    expect(problems).toEqual([]);
});

test('a scheduled article waits on the dashboard', async ({ page, request }) => {
    await newItem(page);
    await page.getByLabel('Title').fill('Waiting one');
    await page.locator('.ProseMirror').click();
    await page.keyboard.type('Not yet');
    await saved(page);
    await page.getByRole('button', { name: 'Schedule…' }).click();
    const when = daysAhead(30);
    await page.getByLabel('Publish at').fill(when);
    await page.locator('#publish').click();
    await expect(page.locator('#scheduled-banner')).toBeVisible();

    // It stays scheduled, so the accessibility checks see the list and the dashboard with it.
    await page.goto('/admin');
    const panel = page.locator('section', { has: page.getByRole('heading', { name: 'Scheduled' }) });
    await expect(panel.getByRole('link', { name: 'Waiting one' })).toBeVisible();
    await expect(panel).toContainText(berlinShown(when));
    expect((await request.get('/waiting-one')).status()).toBe(404);
});
