<?php

declare(strict_types=1);

namespace Edict\Media;

/**
 * Decides what a file is from its first bytes. The uploaded name and the browser's
 * claimed type are never believed. That also keeps playlists away from ffmpeg, which
 * would follow the links inside them.
 */
final class FileType
{
    private const NO_TYPE = ['kind' => 'file', 'extension' => 'bin'];

    /** Kept from the uploaded name. A PDF is recognised by its content instead. */
    private const HARMLESS = [
        'zip', '7z', 'gz', 'tgz', 'bz2', 'xz', 'tar',
        'txt', 'csv', 'tsv', 'md', 'json', 'rtf', 'ics',
        'docx', 'xlsx', 'pptx', 'odt', 'ods', 'odp', 'epub',
    ];

    /**
     * @return array{kind: 'image'|'audio'|'video'|'file', extension: string}
     */
    public static function of(string $path): array
    {
        $head = (string) @file_get_contents($path, false, null, 0, 32);
        $riff = str_starts_with($head, 'RIFF') ? substr($head, 8, 4) : '';

        return match (true) {
            str_starts_with($head, "\xFF\xD8\xFF") => ['kind' => 'image', 'extension' => 'jpg'],
            str_starts_with($head, "\x89PNG\r\n\x1A\n") => ['kind' => 'image', 'extension' => 'png'],
            str_starts_with($head, 'GIF87a'), str_starts_with($head, 'GIF89a') => ['kind' => 'image', 'extension' => 'gif'],
            $riff === 'WEBP' => ['kind' => 'image', 'extension' => 'webp'],
            $riff === 'WAVE' => ['kind' => 'audio', 'extension' => 'wav'],
            $riff === 'AVI ' => ['kind' => 'video', 'extension' => 'avi'],
            substr($head, 4, 4) === 'ftyp' => self::isoMedia(substr($head, 8, 4)),
            // QuickTime files from before "ftyp" existed.
            in_array(substr($head, 4, 4), ['moov', 'mdat', 'wide', 'free', 'skip'], true) => ['kind' => 'video', 'extension' => 'mov'],
            str_starts_with($head, "\x1A\x45\xDF\xA3") => ['kind' => 'video', 'extension' => 'mkv'],
            str_starts_with($head, 'fLaC') => ['kind' => 'audio', 'extension' => 'flac'],
            str_starts_with($head, 'OggS') => ['kind' => 'audio', 'extension' => 'ogg'],
            str_starts_with($head, 'ID3') => ['kind' => 'audio', 'extension' => 'mp3'],
            str_starts_with($head, '%PDF-') => ['kind' => 'file', 'extension' => 'pdf'],
            default => self::mpegAudio($head),
        };
    }

    /**
     * The extension a published file gets. The server picks the type it sends from it,
     * so only types no browser runs as a page or a script are kept, and everything else
     * becomes a plain download. HTML, SVG and XML in particular never pass.
     */
    public static function publicExtension(string $detected, string $originalName): string
    {
        if ($detected !== 'bin') {
            return $detected;
        }
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        return in_array($extension, self::HARMLESS, true) ? $extension : 'bin';
    }

    /**
     * @return array{kind: 'image'|'audio'|'video'|'file', extension: string}
     */
    private static function isoMedia(string $brand): array
    {
        return match (true) {
            in_array($brand, ['avif', 'avis'], true) => ['kind' => 'image', 'extension' => 'avif'],
            in_array($brand, ['heic', 'heix', 'hevc', 'hevx', 'mif1', 'msf1'], true) => ['kind' => 'image', 'extension' => 'heic'],
            in_array($brand, ['M4A ', 'M4B '], true) => ['kind' => 'audio', 'extension' => 'm4a'],
            $brand === 'qt  ' => ['kind' => 'video', 'extension' => 'mov'],
            // Many brands, and a few of them hold only sound. The job looks inside.
            in_array(substr($brand, 0, 3), ['iso', 'mp4', 'avc', 'M4V', 'das', '3gp', '3g2', 'f4v', 'MSN', 'XAV', 'mmp', 'ndx', 'cmf'], true) => ['kind' => 'video', 'extension' => 'mp4'],
            default => self::NO_TYPE,
        };
    }

    /**
     * MP3 and raw AAC have no signature, only a pattern at the start of each frame.
     *
     * @return array{kind: 'image'|'audio'|'video'|'file', extension: string}
     */
    private static function mpegAudio(string $head): array
    {
        if (strlen($head) < 3 || $head[0] !== "\xFF" || (ord($head[1]) & 0xE0) !== 0xE0) {
            return self::NO_TYPE;
        }
        $version = (ord($head[1]) >> 3) & 0b11;
        $layer = (ord($head[1]) >> 1) & 0b11;
        if ($layer === 0) {
            // Raw AAC: the sample rate index must be a real one.
            return ((ord($head[2]) >> 2) & 0b1111) <= 12 ? ['kind' => 'audio', 'extension' => 'aac'] : self::NO_TYPE;
        }
        $bitrate = ord($head[2]) >> 4;
        $rate = (ord($head[2]) >> 2) & 0b11;

        // Layer I is nearly extinct, and looks the same as UTF-16 text's byte order mark.
        return $version !== 1 && $layer !== 3 && $bitrate !== 15 && $rate !== 3 ? ['kind' => 'audio', 'extension' => 'mp3'] : self::NO_TYPE;
    }
}
