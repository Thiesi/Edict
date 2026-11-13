<?php

declare(strict_types=1);

namespace Edict\Site;

/**
 * The themes in this release. Each is a folder in themes/ with its stylesheet and
 * fonts, built into public/assets/build/themes/. New themes arrive as code.
 */
final class Themes
{
    public const DEFAULT = 'decree';

    /** @var array<string, array{name: string, character: string}> */
    public const ALL = [
        'decree' => ['name' => 'Decree', 'character' => 'Edict’s own identity: heraldic, deep colour with a gold accent, formal serif headings.'],
    ];

    public static function exists(string $id): bool
    {
        return isset(self::ALL[$id]);
    }
}
