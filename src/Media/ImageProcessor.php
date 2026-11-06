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
    private const FORMATS = ['avif' => 'Q=60', 'webp' => 'Q=78', 'jpg' => 'Q=82,optimize_coding,background=255'];

    /** The lightbox's version keeps a format browsers can show. */
    private const FULL_FORMATS = ['png' => 'png', 'webp' => 'webp', 'gif' => 'png', 'jpg' => 'jpg', 'avif' => 'jpg', 'heic' => 'jpg'];

    /** A 50 MB file can still decode to gigabytes. */
    private const MAX_PIXELS = 200_000_000;
    private const TIMEOUT_SECONDS = 300;
    private const NO_LIMIT = 10_000_000;
    private const MAX_VARIANTS_ESTIMATE = 200_000_000;

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
        $stmt = $this->pdo->prepare('SELECT sha256, extension, bytes FROM media WHERE id = ?');
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

            // A rough ceiling for all versions together, so this fails before filling anything.
            $this->library->assertRoomFor(min(Row::int($media, 'bytes') * 4, self::MAX_VARIANTS_ESTIMATE));

            // First, because rotating can swap width and height, and the sizes below
            // depend on the upright width.
            $fullFormat = self::FULL_FORMATS[$extension] ?? 'jpg';
            $full = $this->makeVariant($source, $dir, 'full', $fullFormat, '', self::NO_LIMIT);
            $uprightWidth = $full['width'];

            $made = [$full];
            foreach (self::WIDTHS as $i => $target) {
                // Never enlarged. Sizes above the original are skipped, except that
                // something must exist for a very small image.
                if ($target > $uprightWidth && $i > 0) {
                    break;
                }
                foreach (self::FORMATS as $format => $options) {
                    $made[] = $this->makeVariant($source, $dir, 'w' . $target . '-' . $format, $format, $options, min($target, $uprightWidth));
                }
            }

            $this->library->assertRoomFor(array_sum(array_column($made, 'bytes')));

            // Everything built, so now it goes public all at once, and the rows always
            // cover what's there: a crash in between leaves names a retry overwrites.
            $this->pdo->beginTransaction();
            $installed = [];
            try {
                $this->pdo->prepare('DELETE FROM media_variant WHERE media_id = ?')->execute([$mediaId]);
                $insert = $this->pdo->prepare(
                    'INSERT INTO media_variant (media_id, preset, format, width, height, bytes) VALUES (?, ?, ?, ?, ?, ?)',
                );
                foreach ($made as $variant) {
                    $insert->execute([$mediaId, $variant['preset'], $variant['format'], $variant['width'], $variant['height'], $variant['bytes']]);
                    $target = $this->store->variantPath($sha256, $variant['preset'], $variant['format']);
                    $this->store->install($variant['file'], $target);
                    $installed[] = $target;
                }
                $this->pdo->prepare("UPDATE media SET status = 'ready', error = NULL, width = ?, height = ? WHERE id = ?")
                    ->execute([$full['width'], $full['height'], $mediaId]);
                $this->pdo->commit();
            } catch (\Throwable $e) {
                $this->pdo->rollBack();
                foreach ($installed as $path) {
                    MediaStore::remove($path);
                }

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
     * @return array{preset: string, format: string, width: int, height: int, bytes: int, file: string}
     */
    private function makeVariant(string $source, string $dir, string $preset, string $format, string $options, int $width): array
    {
        $file = $dir . '/' . $preset . '.' . $format;
        $save = $options === '' ? '[strip]' : '[strip,' . $options . ']';

        // vipsthumbnail rotates according to EXIF, and "strip" drops all metadata. That
        // would drop the colour profile too, so the colours are converted to sRGB first:
        // a wide-gamut phone photo would look dull otherwise.
        // The huge height means only the width limits the size, and ">" means it
        // only ever shrinks.
        Command::run([
            $this->config->vipsthumbnailBin,
            $source,
            '--size', $width . 'x' . self::NO_LIMIT . '>',
            '--export-profile', 'srgb',
            '--output', $file . $save,
        ], self::TIMEOUT_SECONDS, $dir);

        [$actualWidth, $actualHeight] = $this->measure($file);
        $bytes = filesize($file);
        if ($bytes === false) {
            throw new MediaError('Could not read a generated image.');
        }

        return ['preset' => $preset, 'format' => $format, 'width' => $actualWidth, 'height' => $actualHeight, 'bytes' => $bytes, 'file' => $file];
    }

    /**
     * @return array{int, int}
     */
    private function measure(string $path): array
    {
        $output = Command::run([$this->config->vipsheaderBin, '-f', 'width', $path], 60, dirname($path));
        $height = Command::run([$this->config->vipsheaderBin, '-f', 'height', $path], 60, dirname($path));

        return [(int) trim($output), (int) trim($height)];
    }
}
