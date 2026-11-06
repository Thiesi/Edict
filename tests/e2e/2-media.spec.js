import { execFileSync } from 'node:child_process';
import { statSync } from 'node:fs';

import { expect, test } from '@playwright/test';

const CHUNK_BYTES = 8_388_608;

const newArticle = async (page) => {
    await page.goto('/admin/articles');
    await page.getByRole('button', { name: 'New article' }).click();
    await expect(page.locator('.ProseMirror')).toBeVisible();
};

const saved = (page) => expect(page.locator('#save-status')).toContainText('Saved', { timeout: 15_000 });

const generated = (test, name, width, height) => {
    const path = test.outputPath(name);
    execFileSync('vips', ['gaussnoise', path, String(width), String(height)]);

    return path;
};

test('a large photo uploads in chunks, gets processed and opens in the lightbox', async ({ page, request }, info) => {
    test.setTimeout(240_000);
    const problems = [];
    page.on('pageerror', (error) => problems.push(error.message));

    const path = generated(info, 'large.jpg', 5000, 5000);
    expect(statSync(path).size, 'must need several chunks').toBeGreaterThan(CHUNK_BYTES);

    await newArticle(page);
    await page.getByLabel('Title').fill('With a photo');
    await page.locator('#file-input').setInputFiles(path);

    const block = page.locator('.editor-media');
    await expect(block.locator('img')).toBeVisible({ timeout: 180_000 });
    await block.getByLabel(/Description/).fill('Some noise');
    await block.getByLabel(/Caption/).fill('A caption');
    await saved(page);

    await page.reload();
    await expect(page.locator('.editor-media').getByLabel(/Description/)).toHaveValue('Some noise');

    await page.locator('#publish').click();
    await expect(page.getByRole('link', { name: 'View', exact: true })).toBeVisible();
    await page.waitForLoadState('load');

    await page.goto('/with-a-photo');
    const picture = page.locator('article picture img');
    await expect(picture).toHaveAttribute('alt', 'Some noise');
    await expect(page.locator('figcaption')).toHaveText('A caption');
    await picture.scrollIntoViewIfNeeded();
    await expect.poll(() => picture.evaluate((image) => image.naturalWidth)).toBeGreaterThan(0);

    await page.locator('a.lightbox').click();
    const shown = page.locator('dialog.lightbox-dialog[open] img');
    await expect(shown).toBeVisible();
    await expect.poll(() => shown.evaluate((image) => image.naturalWidth), { timeout: 30_000 }).toBe(5000);
    await page.keyboard.press('Escape');
    await expect(page.locator('dialog.lightbox-dialog[open]')).toHaveCount(0);

    expect(problems).toEqual([]);
    expect((await request.get(new URL(await page.locator('a.lightbox').getAttribute('href'), 'http://localhost:8080').href)).status()).toBe(200);
});

test('several files make a gallery that swipes through', async ({ page }, info) => {
    test.setTimeout(180_000);
    await newArticle(page);
    await page.getByLabel('Title').fill('A gallery');
    await page.locator('#file-input').setInputFiles([
        generated(info, 'one.jpg', 800, 600),
        generated(info, 'two.jpg', 700, 500),
    ]);

    await expect(page.locator('.editor-gallery img:visible')).toHaveCount(2, { timeout: 120_000 });
    await saved(page);
    await page.locator('#publish').click();
    await expect(page.getByRole('link', { name: 'View', exact: true })).toBeVisible();
    await page.waitForLoadState('load');

    await page.goto('/a-gallery');
    await expect(page.locator('.media-gallery a.lightbox')).toHaveCount(2);

    await page.locator('a.lightbox').first().click();
    const shown = page.locator('dialog.lightbox-dialog[open] img');
    await expect(shown).toHaveAttribute('width', '800');
    await page.keyboard.press('ArrowRight');
    await expect(shown).toHaveAttribute('width', '700');
    await page.keyboard.press('ArrowRight');
    await expect(shown).toHaveAttribute('width', '800');
});

test('the media library reuses an uploaded image', async ({ page }) => {
    await newArticle(page);

    await page.locator('#media-toggle').click();
    const panel = page.locator('#media-panel');
    await expect(panel.locator('button[data-media-id]').first()).toBeVisible();
    await panel.locator('button[data-media-id]').first().click();
    await panel.getByRole('button', { name: 'Insert' }).click();

    await expect(page.locator('.editor-media img')).toBeVisible();
});

test('a file that is not an image says so and adds nothing', async ({ page }, info) => {
    await newArticle(page);
    const path = info.outputPath('notes.txt');
    execFileSync('sh', ['-c', `echo notes > "${path}"`]);

    await page.locator('#file-input').setInputFiles(path);

    await expect(page.locator('#upload-tray')).toContainText('Only images');
    await expect(page.locator('.editor-media')).toHaveCount(0);
});

test('removing an image block only removes the block', async ({ page }) => {
    await newArticle(page);
    await page.locator('#media-toggle').click();
    await page.locator('#media-panel button[data-media-id]').first().click();
    await page.locator('#media-panel').getByRole('button', { name: 'Insert' }).click();
    await expect(page.locator('.editor-media')).toHaveCount(1);

    await page.locator('.editor-media .media-remove').click();

    await expect(page.locator('.editor-media')).toHaveCount(0);
    await saved(page);
});
