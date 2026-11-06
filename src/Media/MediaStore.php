<?php

declare(strict_types=1);

namespace Edict\Media;

use Edict\Config;

/**
 * Where media lives on disk. Paths come only from hashes and fixed names, in a
 * fan-out tree, never from anything uploaded. Modes are set explicitly: Apache reads
 * "media" as "other", and must not be able to read anything else.
 */
final readonly class MediaStore
{
    public function __construct(private Config $config) {}

    public function isConfigured(): bool
    {
        return $this->config->dataDir !== null;
    }

    public function uploadPath(string $uploadId): string
    {
        return $this->root('upload-tmp') . '/' . $uploadId;
    }

    /**
     * The untouched original, with its metadata. Never publicly reachable.
     */
    public function originalPath(string $sha256): string
    {
        return $this->root('private') . '/' . self::fanOut($sha256) . '/' . $sha256;
    }

    /**
     * A public file. Metadata has been stripped from it.
     */
    public function variantPath(string $sha256, string $preset, string $format): string
    {
        return $this->root('media') . '/' . self::relativeVariantPath($sha256, $preset, $format);
    }

    public static function relativeVariantPath(string $sha256, string $preset, string $format): string
    {
        return self::fanOut($sha256) . '/' . $sha256 . '-' . $preset . '.' . $format;
    }

    /**
     * A new temp directory for one job. The caller removes it in `finally`.
     */
    public function newJobDir(): string
    {
        $dir = $this->root('job-tmp') . '/' . bin2hex(random_bytes(8));
        self::makeDir($dir, 0o700);

        return $dir;
    }

    /**
     * Moves a finished file to its place. The rename is atomic, so a reader never
     * sees half a file.
     */
    public function install(string $from, string $to): void
    {
        $private = !str_starts_with($to, $this->root('media') . '/');
        self::makeDir(dirname($to), $private ? 0o700 : 0o755);
        if (!rename($from, $to) || !chmod($to, $private ? 0o600 : 0o644)) {
            throw new MediaError('Could not store the file.');
        }
    }

    /**
     * Removes temp files left by crashed jobs and abandoned uploads.
     */
    public function sweep(int $olderThan): void
    {
        foreach (['job-tmp', 'upload-tmp'] as $area) {
            foreach (scandir($this->root($area)) ?: [] as $name) {
                $path = $this->root($area) . '/' . $name;
                if ($name !== '.' && $name !== '..' && filemtime($path) < $olderThan) {
                    self::remove($path);
                }
            }
        }
    }

    public function freeBytes(): int
    {
        $free = disk_free_space($this->root('media'));

        return $free === false ? 0 : (int) $free;
    }

    public static function remove(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            foreach (scandir($path) ?: [] as $name) {
                if ($name !== '.' && $name !== '..') {
                    self::remove($path . '/' . $name);
                }
            }
            @rmdir($path);
        } else {
            @unlink($path);
        }
    }

    private function root(string $area): string
    {
        $base = $this->config->dataDir;
        if ($base === null) {
            throw new MediaError('Media storage is not set up yet (DATA_DIR).');
        }

        $dir = $base . '/' . $area;
        self::makeDir($dir, $area === 'media' ? 0o755 : 0o700);

        return $dir;
    }

    private static function fanOut(string $sha256): string
    {
        return substr($sha256, 0, 2) . '/' . substr($sha256, 2, 2);
    }

    /**
     * chmod afterwards, since the process's umask may have narrowed the mode.
     */
    private static function makeDir(string $dir, int $mode): void
    {
        if (is_dir($dir)) {
            return;
        }
        if (!@mkdir($dir, $mode, true) && !is_dir($dir)) {
            throw new MediaError('Could not create a media directory.');
        }

        // Also the parents mkdir just made, up to the area's root.
        for ($path = $dir; basename($path) !== '' && strlen(basename($path)) <= 2 || $path === $dir; $path = dirname($path)) {
            @chmod($path, $mode);
        }
    }
}
