<?php

declare(strict_types=1);

namespace Edict\Tests\Content;

use Edict\Content\Body;
use Edict\Content\BodyRenderer;
use Edict\Media\MediaStore;
use Edict\Tests\DbTestCase;

final class BodyRendererTest extends DbTestCase
{
    private function mediaRow(string $status = 'ready', int $variantsUpTo = 960): int
    {
        $sha = hash('sha256', random_bytes(8));
        $stmt = $this->pdo->prepare(
            "INSERT INTO media (sha256, kind, extension, bytes, original_name, width, height, status, created_at)
             VALUES (?, 'image', 'jpg', 1000, 'x.jpg', 2000, 1000, ?, now()) RETURNING id",
        );
        $stmt->execute([$sha, $status]);
        $id = (int) $stmt->fetchColumn();

        $insert = $this->pdo->prepare('INSERT INTO media_variant (media_id, preset, format, width, height, bytes) VALUES (?, ?, ?, ?, ?, 10)');
        foreach ([480, 960, 1600] as $width) {
            if ($width <= $variantsUpTo) {
                foreach (['avif', 'webp', 'jpg'] as $format) {
                    $insert->execute([$id, "w$width-$format", $format, $width, (int) ($width / 2)]);
                }
            }
        }
        $insert->execute([$id, 'full', 'jpg', 2000, 1000]);

        return $id;
    }

    /**
     * @param array<string, mixed> $node
     */
    private function render(array $node): string
    {
        return $this->service(BodyRenderer::class)->html(Body::encode(['type' => 'doc', 'content' => [$node]]));
    }

    public function testImageBecomesAPictureInAFullSizeLink(): void
    {
        $id = $this->mediaRow();

        $html = $this->render(['type' => 'image', 'attrs' => ['mediaId' => $id, 'alt' => 'A <cat>', 'caption' => 'Her "name" & more']]);

        self::assertStringContainsString('<figure class="media-image">', $html);
        self::assertMatchesRegularExpression('~<a class="lightbox" href="/media/[0-9a-f/]+-full\.jpg" data-width="2000" data-height="1000">~', $html);
        self::assertMatchesRegularExpression('~<source type="image/avif" srcset="[^"]+-w480-avif\.avif 480w, [^"]+-w960-avif\.avif 960w"~', $html);
        self::assertStringContainsString('<source type="image/webp"', $html);
        self::assertStringContainsString('alt="A &lt;cat&gt;"', $html);
        self::assertStringContainsString('loading="lazy"', $html);
        self::assertStringContainsString('width="960" height="480"', $html, 'reserves its space while loading');
        self::assertStringContainsString('<figcaption>Her &quot;name&quot; &amp; more</figcaption>', $html);
    }

    public function testASmallImageWithOneSizeStillRenders(): void
    {
        $id = $this->mediaRow(variantsUpTo: 480);

        $html = $this->render(['type' => 'image', 'attrs' => ['mediaId' => $id]]);

        self::assertStringContainsString('-w480-jpg.jpg" srcset', $html);
        self::assertStringNotContainsString('figcaption', $html);
    }

    public function testAnAnimatedGifPlaysAsAMutedLoop(): void
    {
        $id = $this->mediaRow();
        $this->pdo->prepare("INSERT INTO media_variant (media_id, preset, format, width, height, bytes) VALUES (?, 'loop', 'mp4', 640, 320, 10)")->execute([$id]);

        $html = $this->render(['type' => 'image', 'attrs' => ['mediaId' => $id, 'alt' => 'A <dancing> cat']]);

        self::assertMatchesRegularExpression(
            '~<video class="media-loop" src="/media/[^"]+-loop\.mp4" poster="/media/[^"]+-w960-jpg\.jpg" width="640" height="320" autoplay loop muted playsinline disablepictureinpicture role="img" aria-label="A &lt;dancing&gt; cat"></video>~',
            $html,
        );
        self::assertStringNotContainsString('lightbox', $html, 'a still full-size picture is not what it is');

        $gallery = $this->render(['type' => 'gallery', 'attrs' => ['items' => [['mediaId' => $id], ['mediaId' => $this->mediaRow()]]]]);
        self::assertStringContainsString('aria-hidden="true"', $gallery, 'without a description it is decoration');
        self::assertSame(1, substr_count($gallery, 'class="media-loop"'));
        self::assertSame(1, substr_count($gallery, 'class="lightbox"'));
    }

    public function testImagesThatArentReadyAreLeftOut(): void
    {
        foreach (['processing', 'failed'] as $status) {
            self::assertSame('', $this->render(['type' => 'image', 'attrs' => ['mediaId' => $this->mediaRow($status)]]), $status);
        }
        self::assertSame('', $this->render(['type' => 'image', 'attrs' => ['mediaId' => 999_999]]));
        self::assertSame('', $this->render(['type' => 'image', 'attrs' => ['mediaId' => 'x']]));
    }

    public function testGalleryRendersItsReadyImages(): void
    {
        $html = $this->render(['type' => 'gallery', 'attrs' => ['items' => [
            ['mediaId' => $this->mediaRow(), 'alt' => 'One'],
            ['mediaId' => $this->mediaRow('processing'), 'alt' => 'Waiting'],
            ['mediaId' => $this->mediaRow(), 'alt' => 'Two'],
        ]]]);

        self::assertStringContainsString('<div class="media-gallery">', $html);
        self::assertSame(2, substr_count($html, 'class="lightbox"'));
        self::assertStringContainsString('alt="One"', $html);
        self::assertStringNotContainsString('Waiting', $html);
        self::assertSame('', $this->render(['type' => 'gallery', 'attrs' => ['items' => []]]));
    }

    public function testImagesInsideOtherBlocksAreFound(): void
    {
        $id = $this->mediaRow();

        $html = $this->render(['type' => 'blockquote', 'content' => [['type' => 'image', 'attrs' => ['mediaId' => $id]]]]);

        self::assertStringContainsString('<blockquote>', $html);
        self::assertStringContainsString('class="lightbox"', $html);
    }

    public function testPublicPagesShowImagesThroughTheTemplateFilter(): void
    {
        self::assertInstanceOf(MediaStore::class, $this->service(MediaStore::class));
        $id = $this->mediaRow();
        $this->pdo->prepare(
            "INSERT INTO article (slug, lang, title, body_json, status, published_at, created_at, updated_at)
             VALUES ('with-image', 'en', 'With image', ?, 'published', to_timestamp(1), now(), now())",
        )->execute([Body::encode(['type' => 'doc', 'content' => [['type' => 'image', 'attrs' => ['mediaId' => $id, 'alt' => 'Shown']]]])]);

        $page = (string) $this->get('/with-image')->getBody();

        self::assertStringContainsString('alt="Shown"', $page);
        self::assertStringContainsString('/assets/lightbox.js', $page);
    }

    private function playerRow(string $kind, string $status = 'ready'): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO media (sha256, kind, extension, bytes, original_name, width, height, status, created_at)
             VALUES (?, ?, 'mp4', 1000, 'x', 1280, 720, ?, now()) RETURNING id",
        );
        $stmt->execute([hash('sha256', random_bytes(8)), $kind, $status]);
        $id = (int) $stmt->fetchColumn();

        $insert = $this->pdo->prepare('INSERT INTO media_variant (media_id, preset, format, width, height, bytes) VALUES (?, ?, ?, ?, ?, 10)');
        if ($kind === 'video') {
            $insert->execute([$id, 'h264', 'mp4', 1280, 720]);
            $insert->execute([$id, 'poster', 'jpg', 1280, 720]);
        } else {
            $insert->execute([$id, 'aac', 'm4a', 0, 0]);
        }

        return $id;
    }

    public function testVideoBecomesAPlayerWithAPoster(): void
    {
        $id = $this->playerRow('video');

        $html = $this->render(['type' => 'player', 'attrs' => ['mediaId' => $id, 'caption' => 'A <clip>']]);

        self::assertMatchesRegularExpression('~<video controls playsinline preload="metadata" src="/media/[^"]+-h264\.mp4" poster="/media/[^"]+-poster\.jpg" width="1280" height="720">~', $html);
        self::assertStringContainsString('<figcaption>A &lt;clip&gt;</figcaption>', $html);
        self::assertStringNotContainsString('autoplay', $html);
    }

    public function testAChosenPosterIsUsed(): void
    {
        $id = $this->playerRow('video');
        $this->pdo->prepare("UPDATE media_variant SET preset = 'poster-12500' WHERE media_id = ? AND preset = 'poster'")->execute([$id]);

        $html = $this->render(['type' => 'player', 'attrs' => ['mediaId' => $id]]);

        self::assertMatchesRegularExpression('~poster="/media/[^"]+-poster-12500\.jpg"~', $html);
    }

    public function testAudioBecomesAPlayer(): void
    {
        $html = $this->render(['type' => 'player', 'attrs' => ['mediaId' => $this->playerRow('audio')]]);

        self::assertMatchesRegularExpression('~<audio controls preload="metadata" src="/media/[^"]+-aac\.m4a">~', $html);
        self::assertStringNotContainsString('figcaption', $html);
    }

    public function testAudioCarriesItsLengthAndWaveformForTheSitePlayer(): void
    {
        $id = $this->playerRow('audio');
        $this->pdo->prepare('UPDATE media SET meta = ?::jsonb WHERE id = ?')->execute(['{"seconds": 61.237, "peaks": [0, 50, 100, 140, -3, "x\\"><script>"]}', $id]);

        $html = $this->render(['type' => 'player', 'attrs' => ['mediaId' => $id]]);

        self::assertStringContainsString('<figure class="media-audio" data-seconds="61.24" data-peaks="0 50 100 100 0">', $html);
        // The browser's own player stays, for anyone without JavaScript.
        self::assertStringContainsString('<audio controls preload="metadata"', $html);
    }

    private function fileRow(string $format, bool $preview = false): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO media (sha256, kind, extension, bytes, original_name, status, created_at)
             VALUES (?, 'file', ?, 1234567, 'Big \"plan\" <v2>.' || ?, 'ready', now()) RETURNING id",
        );
        $stmt->execute([hash('sha256', random_bytes(8)), $format, $format]);
        $id = (int) $stmt->fetchColumn();
        $insert = $this->pdo->prepare('INSERT INTO media_variant (media_id, preset, format, width, height, bytes) VALUES (?, ?, ?, ?, ?, 10)');
        $insert->execute([$id, 'file', $format, 0, 0]);
        if ($preview) {
            $insert->execute([$id, 'preview', 'jpg', 640, 905]);
        }

        return $id;
    }

    public function testAFileBecomesADownloadCardUnderItsOwnName(): void
    {
        $html = $this->render(['type' => 'file', 'attrs' => ['mediaId' => $this->fileRow('zip'), 'caption' => 'All of it']]);

        self::assertStringContainsString('<figure class="media-file">', $html);
        self::assertMatchesRegularExpression('~<a class="file-card" href="/media/[^"]+-file\.zip" download="Big &quot;plan&quot; &lt;v2&gt;\.zip">~', $html);
        self::assertStringContainsString('<span class="file-about">ZIP · 1.2 MB</span>', $html);
        self::assertStringContainsString('class="file-icon"', $html);
        self::assertStringContainsString('<figcaption>All of it</figcaption>', $html);
    }

    public function testAPdfOpensInTheBrowserWithItsFirstPageAndCanStillBeDownloaded(): void
    {
        $html = $this->render(['type' => 'file', 'attrs' => ['mediaId' => $this->fileRow('pdf', preview: true)]]);

        self::assertMatchesRegularExpression('~<a class="file-card" href="/media/[^"]+-file\.pdf" type="application/pdf"><img src="/media/[^"]+-preview\.jpg" width="640" height="905" alt=""~', $html);
        self::assertMatchesRegularExpression('~<a class="file-download" href="/media/[^"]+-file\.pdf" download="[^"]+">Download</a>~', $html);
    }

    public function testAnUnknownTypeIsJustAFile(): void
    {
        self::assertStringContainsString('<span class="file-about">File · 1.2 MB</span>', $this->render(['type' => 'file', 'attrs' => ['mediaId' => $this->fileRow('bin')]]));
    }

    public function testAFileNotYetPublishedShowsNothing(): void
    {
        $id = $this->fileRow('zip');
        $this->pdo->prepare('DELETE FROM media_variant WHERE media_id = ?')->execute([$id]);

        self::assertSame('', trim($this->render(['type' => 'file', 'attrs' => ['mediaId' => $id]])));
        self::assertSame('', trim($this->render(['type' => 'file', 'attrs' => ['mediaId' => $this->mediaRow()]])), 'an image is not a file');
    }

    public function testPlayersWaitUntilTheyAreReady(): void
    {
        self::assertSame('', trim($this->render(['type' => 'player', 'attrs' => ['mediaId' => $this->playerRow('video', 'processing')]])));
    }

    public function testAPlayerPointedAtAnImageShowsNothing(): void
    {
        self::assertSame('', trim($this->render(['type' => 'player', 'attrs' => ['mediaId' => $this->mediaRow()]])));
    }

    public function testPlayerAttributesAreCleaned(): void
    {
        $clean = Body::clean(['type' => 'doc', 'content' => [
            ['type' => 'player', 'attrs' => ['mediaId' => 7, 'caption' => 'ok', 'onclick' => 'x']],
            ['type' => 'player', 'attrs' => ['mediaId' => 'nope']],
            ['type' => 'file', 'attrs' => ['mediaId' => 8, 'caption' => 'f', 'href' => 'javascript:x']],
        ]]);

        self::assertSame([
            ['type' => 'player', 'attrs' => ['mediaId' => 7, 'caption' => 'ok']],
            ['type' => 'file', 'attrs' => ['mediaId' => 8, 'caption' => 'f']],
        ], $clean['content']);
        self::assertSame([7, 8], Body::mediaIds($clean));
    }
}
