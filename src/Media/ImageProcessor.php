<?php

declare(strict_types=1);

namespace Edict\Media;

use Edict\Config;
use Edict\Database\Row;

/**
 * Makes the public versions of an image with libvips: rotated upright, metadata
 * stripped (GPS positions included), in the widths and formats readers need. The
 * original stays private, so nothing with metadata is ever public.
 */
final readonly class ImageProcessor
{
    public const JOB = 'image';

    private const WIDTHS = [480, 960, 1600, 2400];
    private const FORMATS = ['avif' => 'Q=60', 'webp' => 'Q=78', 'jpg' => 'Q=82,optimize_coding'];

    /** The lightbox's version keeps a format browsers can show. */
    private const FULL_FORMATS = ['png' => 'png', 'webp' => 'webp', 'gif' => 'gif', 'jpg' => 'jpg', 'avif' => 'jpg', 'heic' => 'jpg'];

    /** A 50 MB file can still decode to gigabytes. */
    private const MAX_PIXELS = 200_000_000;
    private const TIMEOUT_SECONDS = 300;
    private const NO_LIMIT = 10_000_000;

    public function __construct(
        private \PDO $pdo,
        private MediaStore $store,
        private MediaLibrary $library,
        private Config $config,
    ) {}

    /**
     * @throws MediaError
     */
    public function process(int $mediaId): void
    {
        $stmt = $this->pdo->prepare('SELECT sha256, extension, width FROM media WHERE id = ?');
        $stmt->execute([$mediaId]);
        $media = $stmt->fetch();
        if (!is_array($media)) {
            return; // Deleted since the job was queued.
        }
        $sha256 = Row::str($media, 'sha256');
        $extension = Row::str($media, 'extension');

        $dir = $this->store->newJobDir();
        try {
            // Named for the type, which is how the tools recognise it.
            $source = $dir . '/source.' . $extension;
            if (!copy($this->store->originalPath($sha256), $source)) {
                throw new MediaError('Could not read the original.');
            }

            [$width, $height] = $this->measure($source);
            if ($width * $height > self::MAX_PIXELS) {
                throw new MediaError('The image has too many pixels (limit: ' . (self::MAX_PIXELS / 1_000_000) . ' megapixels).');
            }

            $this->library->assertRoomFor(0);

            $made = [];
            foreach (self::WIDTHS as $target) {
                // Never enlarged. Sizes above the original are skipped, except that
                // something must exist for a very small image.
                if ($target > $width && $made !== []) {
                    break;
                }
                foreach (self::FORMATS as $format => $options) {
                    $made[] = $this->makeVariant($source, $dir, $sha256, 'w' . $target . '-' . $format, $format, $options, min($target, $width));
                }
            }

            $fullFormat = self::FULL_FORMATS[$extension] ?? 'jpg';
            $full = $this->makeVariant($source, $dir, $sha256, 'full', $fullFormat, '', self::NO_LIMIT);
            $made[] = $full;

            $this->pdo->beginTransaction();
            try {
                $this->pdo->prepare('DELETE FROM media_variant WHERE media_id = ?')->execute([$mediaId]);
                $insert = $this->pdo->prepare(
                    'INSERT INTO media_variant (media_id, preset, format, width, height, bytes) VALUES (?, ?, ?, ?, ?, ?)',
                );
                foreach ($made as $variant) {
                    $insert->execute([$mediaId, $variant['preset'], $variant['format'], $variant['width'], $variant['height'], $variant['bytes']]);
                }
                // The full version's size, because rotating can swap width and height.
                $this->pdo->prepare("UPDATE media SET status = 'ready', error = NULL, width = ?, height = ? WHERE id = ?")
                    ->execute([$full['width'], $full['height'], $mediaId]);
                $this->pdo->commit();
            } catch (\Throwable $e) {
                $this->pdo->rollBack();

                throw $e;
            }
        } finally {
            MediaStore::remove($dir);
        }
    }

    public function markFailed(int $mediaId, string $error): void
    {
        $this->pdo->prepare("UPDATE media SET status = 'failed', error = ? WHERE id = ?")->execute([mb_substr($error, 0, 500), $mediaId]);
    }

    /**
     * @return array{preset: string, format: string, width: int, height: int, bytes: int}
     */
    private function makeVariant(string $source, string $dir, string $sha256, string $preset, string $format, string $options, int $width): array
    {
        $file = $dir . '/' . $preset . '.' . $format;
        $save = $options === '' ? '[strip]' : '[strip,' . $options . ']';

        // vipsthumbnail rotates according to EXIF, and "strip" drops all metadata.
        // The huge height means only the width limits the size, and ">" means it
        // only ever shrinks.
        Command::run([
            $this->config->vipsthumbnailBin,
            $source,
            '--size', $width . 'x' . self::NO_LIMIT . '>',
            '--output', $file . $save,
        ], self::TIMEOUT_SECONDS);

        [$actualWidth, $actualHeight] = $this->measure($file);
        $bytes = filesize($file);
        if ($bytes === false) {
            throw new MediaError('Could not read a generated image.');
        }

        $this->store->install($file, $this->store->variantPath($sha256, $preset, $format));

        return ['preset' => $preset, 'format' => $format, 'width' => $actualWidth, 'height' => $actualHeight, 'bytes' => $bytes];
    }

    /**
     * @return array{int, int}
     */
    private function measure(string $path): array
    {
        $output = Command::run([$this->config->vipsheaderBin, '-f', 'width', $path], 60);
        $height = Command::run([$this->config->vipsheaderBin, '-f', 'height', $path], 60);

        return [(int) trim($output), (int) trim($height)];
    }
}
