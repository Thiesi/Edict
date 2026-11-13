<?php

declare(strict_types=1);

namespace Edict\Media;

use Edict\Config;
use Edict\Database\Row;

/**
 * Publishes a file that is neither picture nor sound: a copy of it, exactly as
 * uploaded, under a name whose extension is one the server sends harmlessly. A PDF
 * also gets a picture of its first page for its card.
 */
final readonly class FileProcessor
{
    public const JOB = 'file';

    private const PREVIEW_WIDTH = 640;
    private const PREVIEW_SECONDS = 120;
    private const COPY_BYTES = 8_388_608;

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
        $stmt = $this->pdo->prepare("SELECT sha256, extension, bytes FROM media WHERE id = ? AND kind = 'file'");
        $stmt->execute([$mediaId]);
        $media = $stmt->fetch();
        if (!is_array($media)) {
            return; // Deleted since the job was queued.
        }
        $sha256 = Row::str($media, 'sha256');
        $extension = Row::str($media, 'extension');
        $bytes = Row::int($media, 'bytes');

        $dir = $this->store->newJobDir();
        try {
            $this->library->assertRoomFor($bytes);
            $copy = $dir . '/file.' . $extension;
            self::copy($this->store->originalPath($sha256), $copy);
            $made = [['preset' => 'file', 'format' => $extension, 'width' => 0, 'height' => 0, 'bytes' => $bytes, 'file' => $copy]];

            $width = 0;
            $height = 0;
            if ($extension === 'pdf') {
                $preview = $this->preview($copy, $dir);
                if ($preview !== null) {
                    $made[] = $preview;
                    ['width' => $width, 'height' => $height] = $preview;
                }
            }

            $this->library->publish($mediaId, $sha256, $made, $width, $height);
        } finally {
            MediaStore::remove($dir);
        }
    }

    /**
     * In pieces: a file can be gigabytes.
     */
    private static function copy(string $from, string $to): void
    {
        $in = @fopen($from, 'r');
        $out = @fopen($to, 'w');
        try {
            if ($in === false || $out === false) {
                throw new MediaError('Could not copy the file.');
            }
            while (!feof($in)) {
                $chunk = fread($in, self::COPY_BYTES);
                if ($chunk === false || fwrite($out, $chunk) !== strlen($chunk)) {
                    throw new MediaError('Could not copy the file.');
                }
            }
        } finally {
            if (is_resource($in)) {
                fclose($in);
            }
            if (is_resource($out)) {
                fclose($out);
            }
        }
    }

    /**
     * The first page. A PDF that won't render still gets its card, only without a picture.
     *
     * @return array{preset: string, format: string, width: int, height: int, bytes: int, file: string}|null
     */
    private function preview(string $pdf, string $dir): ?array
    {
        $file = $dir . '/preview.jpg';
        try {
            Command::run([
                $this->config->vipsthumbnailBin,
                $pdf,
                '--size', self::PREVIEW_WIDTH . 'x10000000>',
                '--export-profile', 'srgb',
                '--output', $file . '[strip,Q=82,optimize_coding,background=255]',
            ], self::PREVIEW_SECONDS, $dir);
            $width = (int) trim(Command::run([$this->config->vipsheaderBin, '-f', 'width', $file], 60, $dir));
            $height = (int) trim(Command::run([$this->config->vipsheaderBin, '-f', 'height', $file], 60, $dir));
        } catch (MediaError) {
            return null;
        }
        $bytes = @filesize($file);
        if ($bytes === false || $bytes === 0 || $width === 0) {
            return null;
        }

        return ['preset' => 'preview', 'format' => 'jpg', 'width' => $width, 'height' => $height, 'bytes' => $bytes, 'file' => $file];
    }
}
