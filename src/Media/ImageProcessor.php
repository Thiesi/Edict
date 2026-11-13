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

    /**
     * An animated GIF also becomes a muted looping video, a fraction of its size. Its
     * transparency goes onto white, as in the JPEG versions: inverted, premultiplied
     * (which puts it on black) and inverted back, it lands on white.
     */
    private const LOOP_FILTER = 'format=gbrap,negate,premultiply=inplace=1,negate,format=gbrp,' . AvProcessor::SCALE . ',format=yuv420p';
    private const LOOP_SECONDS = 600;
    private const LOOP_MAX_BYTES = 200_000_000;

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

            if ($extension === 'gif' && $this->frames($source) > 1) {
                $made[] = $this->loop($source, $dir);
            }

            $this->library->publish($mediaId, $sha256, $made, $full['width'], $full['height']);
        } finally {
            MediaStore::remove($dir);
        }
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

    private function frames(string $gif): int
    {
        try {
            return max(1, (int) trim(Command::run([$this->config->vipsheaderBin, '-f', 'n-pages', $gif], 60, dirname($gif))));
        } catch (MediaError) {
            return 1; // A still picture may not say.
        }
    }

    /**
     * @return array{preset: string, format: string, width: int, height: int, bytes: int, file: string}
     */
    private function loop(string $gif, string $dir): array
    {
        $file = $dir . '/loop.mp4';
        Command::run([
            $this->config->ffmpegBin, '-nostdin', '-hide_banner', '-loglevel', 'error', '-y',
            '-protocol_whitelist', 'file', '-f', 'gif', '-i', $gif,
            '-map', '0:v:0', '-an', '-sn', '-dn', '-map_metadata', '-1',
            '-vf', self::LOOP_FILTER, '-fps_mode', 'vfr',
            '-c:v', 'libx264', '-preset', 'faster', '-crf', '23', '-pix_fmt', 'yuv420p',
            '-threads', '2', '-movflags', '+faststart', '-fs', (string) self::LOOP_MAX_BYTES,
            $file,
        ], self::LOOP_SECONDS, $dir);

        $bytes = @filesize($file);
        if ($bytes === false || $bytes === 0) {
            throw new MediaError('The animation could not be turned into a video.');
        }
        if ($bytes >= self::LOOP_MAX_BYTES) {
            throw new MediaError('The animation is too long to show as a video.', true);
        }
        $size = Command::run([
            $this->config->ffprobeBin, '-v', 'error', '-protocol_whitelist', 'file', '-f', 'mov', '-select_streams', 'v:0',
            '-show_entries', 'stream=width,height', '-of', 'csv=p=0:s=x', $file,
        ], 60, $dir);
        [$width, $height] = array_map(intval(...), explode('x', trim($size)) + [0, 0]);

        return ['preset' => 'loop', 'format' => 'mp4', 'width' => $width, 'height' => $height, 'bytes' => $bytes, 'file' => $file];
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
