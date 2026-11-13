import { execFileSync } from 'node:child_process';
import { readFileSync, statSync } from 'node:fs';

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

test('a cover image is chosen from the library and shows above the article and in the list', async ({ page }) => {
    await newArticle(page);
    await page.getByLabel('Title').fill('With a cover');

    await page.getByRole('button', { name: 'Choose a cover image…' }).click();
    const panel = page.locator('#media-panel');
    await expect(panel).toBeVisible();
    const tiles = panel.locator('button[data-media-id]');
    await expect(tiles.first()).toBeVisible();
    await expect(panel.locator('button.is-audio, button.is-video')).toHaveCount(0);
    // One cover: choosing another replaces the choice.
    await tiles.nth(1).click();
    await tiles.first().click();
    await expect(panel.locator('[aria-pressed="true"]')).toHaveCount(1);
    await panel.getByRole('button', { name: 'Use as cover' }).click();

    await expect(panel).toBeHidden();
    await expect(page.getByRole('img', { name: 'Cover image' })).toBeVisible();
    await expect(page.locator('.editor-media'), 'the text is left alone').toHaveCount(0);
    await page.getByLabel(/Cover description/).fill('The view');
    await saved(page);

    await page.reload();
    await expect(page.getByRole('img', { name: 'Cover image' })).toBeVisible();
    await expect(page.getByLabel(/Cover description/)).toHaveValue('The view');

    await page.locator('#publish').click();
    await expect(page.getByRole('link', { name: 'View', exact: true })).toBeVisible();
    await page.waitForLoadState('load');

    await page.goto('/with-a-cover');
    await expect(page.locator('figure.article-cover img')).toHaveAttribute('alt', 'The view');
    await page.goto('/');
    await expect(page.locator('li', { hasText: 'With a cover' }).locator('.article-cover-thumb img')).toBeVisible();

    await page.goto('/admin/articles');
    await page.getByRole('link', { name: 'With a cover' }).click();
    await page.getByRole('button', { name: 'Remove the cover' }).click();
    await expect(page.getByRole('img', { name: 'Cover image' })).toBeHidden();
    await expect(page.getByLabel(/Cover description/)).toBeHidden();
});

test('an upload lands where it was started and replaces nothing', async ({ page }) => {
    await newArticle(page);
    await page.locator('.ProseMirror').click();
    await page.keyboard.type('Keep this selected');
    await page.keyboard.press('Control+A');

    await page.locator('#media-toggle').click();
    await page.locator('#media-panel button[data-media-id]').first().click();
    await page.locator('#media-panel').getByRole('button', { name: 'Insert' }).click();

    await expect(page.locator('.editor-media')).toHaveCount(1);
    await expect(page.locator('.ProseMirror')).toContainText('Keep this selected');
});

test('undo brings back a description and a removed block', async ({ page }) => {
    await newArticle(page);
    await page.locator('#media-toggle').click();
    await page.locator('#media-panel button[data-media-id]').first().click();
    await page.locator('#media-panel').getByRole('button', { name: 'Insert' }).click();
    const description = page.locator('.editor-media').getByLabel(/Description/);
    await expect(description).toBeVisible();
    // Changes close together count as one undo step.
    await page.waitForTimeout(700);
    await description.fill('First words');
    await expect(page.locator('#save-status')).toContainText(/Unsaved|Saving|Saved/);
    await page.locator('.ProseMirror').focus();

    await page.keyboard.press('Control+Z');
    await expect(description).toHaveValue('');

    await page.keyboard.press('Control+Z');
    await expect(page.locator('.editor-media')).toHaveCount(0);

    await page.keyboard.press('Control+Shift+Z');
    await expect(description).toHaveValue('');
    await page.keyboard.press('Control+Shift+Z');
    await expect(description).toHaveValue('First words');
});

test('pasted text with a picture of itself stays text', async ({ page }) => {
    await newArticle(page);
    await page.locator('.ProseMirror').click();

    await page.evaluate(() => {
        const data = new DataTransfer();
        data.setData('text/html', '<p>From a word processor</p>');
        data.setData('text/plain', 'From a word processor');
        data.items.add(new File(['x'], 'picture.png', { type: 'image/png' }));
        document.querySelector('.ProseMirror').dispatchEvent(new ClipboardEvent('paste', { clipboardData: data, bubbles: true, cancelable: true }));
    });

    await expect(page.locator('.ProseMirror')).toContainText('From a word processor');
    await expect(page.locator('#upload-tray')).toBeHidden();
});

test('a pasted reference to a missing image says so and stops asking', async ({ page }) => {
    await newArticle(page);
    await page.locator('.ProseMirror').click();
    await page.evaluate(() => {
        const data = new DataTransfer();
        data.setData('text/html', '<figure data-media-id="999999"></figure><div data-media-gallery="not json"></div>');
        document.querySelector('.ProseMirror').dispatchEvent(new ClipboardEvent('paste', { clipboardData: data, bubbles: true, cancelable: true }));
    });

    await expect(page.locator('.editor-media')).toContainText('no longer exists');
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

test('typing right after inserting an image continues below it, not over it', async ({ page }) => {
    await newArticle(page);
    await page.locator('#media-toggle').click();
    await page.locator('#media-panel button[data-media-id]').first().click();
    await page.locator('#media-panel').getByRole('button', { name: 'Insert' }).click();
    await expect(page.locator('.editor-media')).toHaveCount(1);
    // Typing while the block was still loading its picture once lost the first letters in CI.
    await expect(page.locator('.editor-media img')).toBeVisible();

    await page.keyboard.type('After the picture');

    await expect(page.locator('.editor-media')).toHaveCount(1);
    await expect(page.locator('.editor-media + p')).toHaveText('After the picture');
});

test('an upload finishing does not pull the cursor out of the title', async ({ page }, info) => {
    test.setTimeout(120_000);
    let release;
    const held = new Promise((resolve) => {
        release = resolve;
    });
    await page.route('**/*', async (route) => {
        if (route.request().method() === 'PUT') {
            await held;
        }
        await route.continue();
    });

    await newArticle(page);
    const upload = page.waitForRequest((request) => request.method() === 'PUT');
    await page.locator('#file-input').setInputFiles(generated(info, 'held.jpg', 640, 480));
    await upload;

    await page.getByLabel('Title').click();
    await page.keyboard.type('Before');
    release();
    await expect(page.locator('.editor-media')).toHaveCount(1, { timeout: 60_000 });

    await page.keyboard.type(' after');
    await expect(page.getByLabel('Title')).toHaveValue('Before after');
    await expect(page.locator('.editor-media')).toHaveCount(1);
});

// Playwright's Chromium has no H.264 or AAC decoder, so these check everything up to the
// player. Actually playing is for real browsers, and part of the test on staging.
test('a video uploads, gets processed and appears as a player with a poster', async ({ page, request }, info) => {
    test.setTimeout(180_000);
    const path = info.outputPath('clip.mp4');
    execFileSync('ffmpeg', ['-nostdin', '-loglevel', 'error', '-y', '-f', 'lavfi', '-i', 'testsrc2=size=640x360:rate=10:duration=2',
        '-f', 'lavfi', '-i', 'sine=frequency=440:duration=2', '-c:v', 'mpeg4', '-c:a', 'aac', path]);

    await newArticle(page);
    await page.getByLabel('Title').fill('With a clip');
    await page.locator('#file-input').setInputFiles(path);

    const block = page.locator('.editor-media');
    await expect(block.getByRole('status')).toContainText('Processing', { timeout: 60_000 });
    await expect(block.locator('video')).toBeVisible({ timeout: 120_000 });

    // Without a decoder the player stays at the start, which is a frame like any other.
    const thumbnail = block.getByRole('img', { name: 'Current poster' });
    await expect(thumbnail).toHaveAttribute('src', /-poster\.jpg$/);
    await block.getByRole('button', { name: 'Use this frame as poster' }).click();
    await expect(block.getByText('Poster changed.')).toBeVisible({ timeout: 60_000 });
    await expect(thumbnail).toHaveAttribute('src', /-poster-0\.jpg$/);
    await expect(block.locator('video')).toHaveAttribute('poster', /-poster-0\.jpg$/);

    await block.getByLabel(/Caption/).fill('A clip');
    await saved(page);

    await page.reload();
    await expect(page.locator('.editor-media').getByLabel(/Caption/)).toHaveValue('A clip');

    await page.locator('#publish').click();
    await expect(page.getByRole('link', { name: 'View', exact: true })).toBeVisible();
    await page.waitForLoadState('load');

    await page.goto('/with-a-clip');
    const video = page.locator('article video');
    await expect(video).toHaveAttribute('controls', '');
    await expect(page.locator('figcaption')).toHaveText('A clip');

    const file = await request.get(await video.getAttribute('src'));
    expect(file.status()).toBe(200);
    expect(file.headers()['content-type']).toBe('video/mp4');
    await expect(video).toHaveAttribute('poster', /-poster-0\.jpg$/);
    const poster = await request.get(await video.getAttribute('poster'));
    expect(poster.headers()['content-type']).toBe('image/jpeg');
});

test('a recording becomes an audio player, and the library offers it', async ({ page }, info) => {
    test.setTimeout(180_000);
    const path = info.outputPath('song.wav');
    execFileSync('ffmpeg', ['-nostdin', '-loglevel', 'error', '-y', '-f', 'lavfi', '-i', 'sine=frequency=440:duration=2', path]);

    await newArticle(page);
    await page.locator('#file-input').setInputFiles(path);
    await expect(page.locator('.editor-media audio')).toBeAttached({ timeout: 120_000 });

    await page.locator('.editor-media .media-remove').click();
    await expect(page.locator('.editor-media')).toHaveCount(0);

    await page.locator('#media-toggle').click();
    const tile = page.locator('#media-panel button[data-media-id]', { hasText: 'song.wav' });
    await expect(tile).toHaveClass(/is-audio/);
    await expect(tile.locator('svg.tile-icon')).toBeVisible();
    await tile.click();
    await page.locator('#media-panel').getByRole('button', { name: 'Insert' }).click();
    await expect(page.locator('.editor-media audio')).toBeAttached();
});

// Chromium here can't decode the H.264 loop, so this checks the markup and the controls around it.
test('an animated GIF becomes a looping video on the page, and a GIF tile in the library', async ({ page }, info) => {
    test.setTimeout(180_000);
    const path = info.outputPath('dance.gif');
    execFileSync('ffmpeg', ['-nostdin', '-loglevel', 'error', '-y', '-f', 'lavfi', '-i', 'testsrc2=size=160x120:rate=10:duration=1', path]);

    await newArticle(page);
    await page.getByLabel('Title').fill('With a dance');
    await page.locator('#file-input').setInputFiles(path);
    const block = page.locator('.editor-media');
    await expect(block.locator('img')).toBeVisible({ timeout: 120_000 });
    await block.getByLabel(/Description/).fill('A test pattern');

    await page.locator('#media-toggle').click();
    await expect(page.locator('#media-panel button[data-media-id]').first()).toHaveClass(/is-animated/);
    await page.locator('#media-toggle').click();

    await saved(page);
    await page.locator('#publish').click();
    await expect(page.getByRole('link', { name: 'View', exact: true })).toBeVisible();
    await page.waitForLoadState('load');

    await page.emulateMedia({ reducedMotion: 'reduce' });
    await page.goto('/with-a-dance');
    const loop = page.getByRole('img', { name: 'A test pattern' });
    await expect(loop).toHaveJSProperty('tagName', 'VIDEO');
    await expect(loop).toHaveJSProperty('muted', true);
    await expect(loop).toHaveJSProperty('loop', true);
    await expect(loop).toHaveAttribute('tabindex', '0');
    await expect(loop).toHaveAttribute('src', /-loop\.mp4$/);
    await expect(loop).toHaveJSProperty('paused', true);
    await expect(page.locator('a.lightbox')).toHaveCount(0);
});

test('an upload shows up in the open library without reopening it', async ({ page }, info) => {
    test.setTimeout(180_000);
    await newArticle(page);
    await page.locator('#media-toggle').click();
    await expect(page.locator('#media-panel button[data-media-id]').first()).toBeVisible();

    await page.locator('#file-input').setInputFiles(generated(info, 'arrives-live.jpg', 640, 480));

    const tile = page.locator('#media-panel button[data-media-id][title="arrives-live.jpg"]');
    await expect(tile.locator('img')).toBeVisible({ timeout: 120_000 });
    await expect(page.locator('#media-panel button[data-waiting-id]')).toHaveCount(0);
    await expect(page.locator('#media-panel button[title="arrives-live.jpg"]')).toHaveCount(1);
});

test('on a phone the library steps aside after inserting', async ({ page }) => {
    await page.setViewportSize({ width: 400, height: 800 });
    await newArticle(page);
    await page.locator('#media-toggle').click();
    await page.locator('#media-panel button[data-media-id]').first().click();
    await page.locator('#media-panel').getByRole('button', { name: 'Insert' }).click();

    await expect(page.locator('#media-panel')).toBeHidden();
    await expect(page.locator('#media-toggle')).toHaveAttribute('aria-expanded', 'false');
    await expect(page.locator('.editor-media')).toBeVisible();
});

test('a published recording gets the site player with waveform, time and speed', async ({ page }, info) => {
    test.setTimeout(180_000);
    const path = info.outputPath('talk.wav');
    execFileSync('ffmpeg', ['-nostdin', '-loglevel', 'error', '-y', '-f', 'lavfi', '-i', "sine=frequency=330:duration=6,volume='if(lt(t,3),0.1,0.9)':eval=frame", path]);

    await newArticle(page);
    await page.getByLabel('Title').fill('With a talk');
    await page.locator('#file-input').setInputFiles(path);
    await expect(page.locator('.editor-media audio')).toBeAttached({ timeout: 120_000 });
    await saved(page);
    await page.locator('#publish').click();
    await expect(page.getByRole('link', { name: 'View', exact: true })).toBeVisible();
    await page.waitForLoadState('load');

    // The test browser has no AAC decoder, so it gets the same sound as WAV, served in
    // ranges as Apache does: without them the browser can't seek.
    const wav = readFileSync(path);
    await page.route('**/*-aac.m4a', (route) => {
        const asked = /bytes=(\d+)-(\d*)/.exec(route.request().headers().range ?? '');
        if (asked === null) {
            return route.fulfill({ status: 200, contentType: 'audio/wav', headers: { 'Accept-Ranges': 'bytes' }, body: wav });
        }
        const from = Number(asked[1]);
        const to = asked[2] === '' ? wav.length - 1 : Math.min(Number(asked[2]), wav.length - 1);

        return route.fulfill({
            status: 206,
            contentType: 'audio/wav',
            headers: { 'Accept-Ranges': 'bytes', 'Content-Range': `bytes ${from}-${to}/${wav.length}` },
            body: wav.subarray(from, to + 1),
        });
    });
    await page.goto('/with-a-talk');

    const player = page.locator('.audio-player');
    await expect(player).toBeVisible();
    await expect(page.locator('figure.media-audio audio')).toBeHidden();
    await expect(player.locator('.audio-bars rect')).toHaveCount(200);
    // Louder in the second half, so the bars say so.
    const heights = await player.locator('.audio-bars rect').evaluateAll((rects) => rects.map((rect) => Number(rect.getAttribute('height'))));
    expect(heights[150]).toBeGreaterThan(heights[50] * 3);
    await expect(player.locator('.audio-time')).toHaveText('0:00 / 0:06');

    const wave = player.getByRole('slider', { name: 'Position' });
    await wave.focus();
    await page.keyboard.press('End');
    await expect(wave).toHaveAttribute('aria-valuenow', '6');
    await page.keyboard.press('Home');
    await expect(wave).toHaveAttribute('aria-valuenow', '0');
    await page.keyboard.press('ArrowRight');
    await expect(wave).toHaveAttribute('aria-valuenow', '5');

    // Clicking the middle of the waveform jumps to the middle.
    const box = await wave.boundingBox();
    await page.mouse.click(box.x + box.width / 2, box.y + box.height / 2);
    await expect(wave).toHaveAttribute('aria-valuenow', '3');

    await player.getByRole('button', { name: 'Playback speed' }).click();
    await expect(player.getByRole('button', { name: 'Playback speed' })).toHaveText('1.25×');
    expect(await page.locator('figure.media-audio audio').evaluate((audio) => audio.playbackRate)).toBe(1.25);

    await player.getByRole('button', { name: 'Play', exact: true }).click();
    await expect(player.getByRole('button', { name: 'Pause', exact: true })).toBeVisible();
    await expect.poll(() => page.locator('figure.media-audio audio').evaluate((audio) => audio.currentTime)).toBeGreaterThan(3.2);
    await player.getByRole('button', { name: 'Pause', exact: true }).click();
    await expect(player.getByRole('button', { name: 'Play', exact: true })).toBeVisible();
});
