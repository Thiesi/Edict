<?php

declare(strict_types=1);

namespace Edict\Media;

/**
 * Decides what a file is from its first bytes. The uploaded name and the browser's
 * claimed type are never believed.
 */
final class FileType
{
    /**
     * @return array{kind: 'image'|'file', extension: string}
     */
    public static function of(string $path): array
    {
        $head = (string) @file_get_contents($path, false, null, 0, 32);

        $image = match (true) {
            str_starts_with($head, "\xFF\xD8\xFF") => 'jpg',
            str_starts_with($head, "\x89PNG\r\n\x1A\n") => 'png',
            str_starts_with($head, 'GIF87a'), str_starts_with($head, 'GIF89a') => 'gif',
            str_starts_with($head, 'RIFF') && substr($head, 8, 4) === 'WEBP' => 'webp',
            substr($head, 4, 4) === 'ftyp' => self::isoImage(substr($head, 8, 4)),
            default => null,
        };

        return $image === null ? ['kind' => 'file', 'extension' => 'bin'] : ['kind' => 'image', 'extension' => $image];
    }

    private static function isoImage(string $brand): ?string
    {
        return match ($brand) {
            'avif', 'avis' => 'avif',
            'heic', 'heix', 'hevc', 'hevx', 'mif1', 'msf1' => 'heic',
            default => null,
        };
    }
}
