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

    /**
     * Each theme's page background, light and dark, as in its theme.css. The admin's
     * accent colour is checked against these (see Accent); a test keeps them in step.
     *
     * @var array<string, array{light: string, dark: string}>
     */
    public const BACKGROUNDS = [
        'decree' => ['light' => '#f7f3ea', 'dark' => '#14121f'],
        'broadsheet' => ['light' => '#fbfaf6', 'dark' => '#161514'],
        'quiet' => ['light' => '#ffffff', 'dark' => '#141414'],
        'almanac' => ['light' => '#f5eedf', 'dark' => '#221e19'],
        'terminal' => ['light' => '#e9efe6', 'dark' => '#050805'],
        'zine' => ['light' => '#fffdf2', 'dark' => '#0d0d0d'],
    ];

    /**
     * The header's background where a theme gives it one of its own, as in its
     * theme.css. An accent that reads well on the page can vanish on such a header,
     * so it is fitted a second time against these; the same test keeps them in step.
     *
     * @var array<string, array{light: string, dark: string}>
     */
    public const HEADER_BACKGROUNDS = [
        'decree' => ['light' => '#1d1a2e', 'dark' => '#0c0b14'],
        'zine' => ['light' => '#0d0d0d', 'dark' => '#ffe600'],
    ];

    /**
     * What sits behind the header in this theme: its own background, or the page's.
     *
     * @return array{light: string, dark: string}|null
     */
    public static function headerBackgrounds(string $id): ?array
    {
        return self::HEADER_BACKGROUNDS[$id] ?? self::BACKGROUNDS[$id] ?? null;
    }

    public static function exists(string $id): bool
    {
        return isset(self::ALL[$id]);
    }
}
