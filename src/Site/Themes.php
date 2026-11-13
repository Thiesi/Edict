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
        'broadsheet' => ['name' => 'Broadsheet', 'character' => 'A newspaper: strong rules, a dense multi-column home page, classic serif type.'],
        'quiet' => ['name' => 'Quiet', 'character' => 'Minimal: one column, lots of white space, nothing competes with the text.'],
        'almanac' => ['name' => 'Almanac', 'character' => 'Warm paper, a notebook feel, generous margins, friendly old-style type.'],
        'terminal' => ['name' => 'Terminal', 'character' => 'Monospace and retro: phosphor green in the dark, for the nerdy articles.'],
        'zine' => ['name' => 'Zine', 'character' => 'Loud: big type, high contrast, an asymmetrical layout, unapologetic colour.'],
    ];

    public static function exists(string $id): bool
    {
        return isset(self::ALL[$id]);
    }
}
