<?php

declare(strict_types=1);

namespace Edict\Content;

/**
 * URL slugs. Articles live directly under the site root, so any first path
 * segment the application uses must be listed here (SlugsRoutesTest enforces it).
 */
final class Slugs
{
    public const MAX_LENGTH = 120;

    private const RESERVED = [
        'account', 'admin', 'archive', 'assets', 'auth', 'favicon', 'feed', 'healthz', 'index',
        'login', 'logout', 'media', 'page', 'robots', 'sitemap', 'tag', 'tags',
    ];

    private const TRANSLITERATION = [
        'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss', 'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'å' => 'a',
        'æ' => 'ae', 'ç' => 'c', 'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e', 'ì' => 'i', 'í' => 'i', 'î' => 'i',
        'ï' => 'i', 'ñ' => 'n', 'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ø' => 'o', 'œ' => 'oe', 'ù' => 'u',
        'ú' => 'u', 'û' => 'u', 'ý' => 'y', 'ÿ' => 'y',
    ];

    /**
     * @return string the slug, which is empty if nothing usable remains
     */
    public static function fromText(string $text): string
    {
        $slug = strtr(mb_strtolower($text), self::TRANSLITERATION);
        $slug = (string) preg_replace('/[^a-z0-9]+/', '-', $slug);

        return trim(substr(trim($slug, '-'), 0, self::MAX_LENGTH), '-');
    }

    public static function isValid(string $slug): bool
    {
        return strlen($slug) <= self::MAX_LENGTH && preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/D', $slug) === 1;
    }

    public static function isReserved(string $slug): bool
    {
        return in_array($slug, self::RESERVED, true);
    }
}
