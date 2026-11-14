<?php

declare(strict_types=1);

namespace Edict\Media;

use Edict\Config;
use Edict\Database\Row;

/**
 * Square PNG icons from an image chosen as the site's icon: the browser tab, the
 * home screen and app lists. Cut from the middle, so any image works; transparency
 * stays. They are ordinary variants of that image, deterministic from its hash.
 */
final readonly class IconProcessor
{
    public const JOB = 'site-icon';
    public const SIZES = [32, 180, 192, 512];
    private const TIMEOUT_SECONDS = 120;

    public function __construct(
        private \PDO $pdo,
        private MediaStore $store,
        private MediaLibrary $library,
        private Config $config,
    ) {}

    public static function preset(int $size): string
    {
        return 'icon-' . $size;
    }

    /**
     * @throws MediaError
     */
    public function process(int $mediaId): void
    {
        $stmt = $this->pdo->prepare("SELECT sha256, extension FROM media WHERE id = ? AND kind = 'image'");
        $stmt->execute([$mediaId]);
        $media = $stmt->fetch();
        if (!is_array($media)) {
            return; // Deleted, or not an image after all.
        }
        $sha256 = Row::str($media, 'sha256');

        $dir = $this->store->newJobDir();
        try {
            $source = $dir . '/source.' . Row::str($media, 'extension');
            if (!copy($this->store->originalPath($sha256), $source)) {
                throw new MediaError('Could not read the original.');
            }
            $made = [];
            foreach (self::SIZES as $size) {
                $file = $dir . '/' . self::preset($size) . '.png';
                Command::run([
                    $this->config->vipsthumbnailBin,
                    $source,
                    '--size', $size . 'x' . $size,
                    '--smartcrop', 'centre',
                    '--export-profile', 'srgb',
                    '--output', $file . '[strip]',
                ], self::TIMEOUT_SECONDS, $dir);
                $bytes = filesize($file);
                if ($bytes === false) {
                    throw new MediaError('Could not read a generated icon.');
                }
                $made[] = ['preset' => self::preset($size), 'format' => 'png', 'width' => $size, 'height' => $size, 'bytes' => $bytes, 'file' => $file];
            }
            $this->library->addVariants($mediaId, $sha256, $made);
        } finally {
            MediaStore::remove($dir);
        }
    }
}
