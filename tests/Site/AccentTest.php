<?php

declare(strict_types=1);

namespace Edict\Tests\Site;

use Edict\Site\Accent;
use Edict\Site\Themes;
use PHPUnit\Framework\TestCase;

final class AccentTest extends TestCase
{
    public function testColoursAreReadInTheUsualForms(): void
    {
        self::assertSame('#1a56db', Accent::parse(' #1A56DB '));
        self::assertSame('#aabbcc', Accent::parse('abc'));
        self::assertNull(Accent::parse('red'));
        self::assertNull(Accent::parse('#12345'));
        self::assertNull(Accent::parse('#1a56db; color: red'));
    }

    public function testAReadableColourStaysExactlyAsChosen(): void
    {
        self::assertSame(['accent' => '#1a56db', 'fg' => '#ffffff', 'adjusted' => false], Accent::fit('#1a56db', '#ffffff'));
    }

    public function testAPaleColourIsDarkenedOnLightAndKeptLightOnDark(): void
    {
        $light = Accent::fit('#ffd400', '#ffffff');
        self::assertTrue($light['adjusted']);
        self::assertGreaterThanOrEqual(4.5, Accent::contrast($light['accent'], '#ffffff'));

        $dark = Accent::fit('#ffd400', '#0d0d0d');
        self::assertFalse($dark['adjusted'], 'already bright enough on black');
        self::assertSame('#111111', $dark['fg']);
    }

    public function testADarkColourIsLightenedOnDarkThemes(): void
    {
        $fit = Accent::fit('#0b2a6b', '#050805');

        self::assertTrue($fit['adjusted']);
        self::assertGreaterThanOrEqual(4.5, Accent::contrast($fit['accent'], '#050805'));
        self::assertSame('#111111', $fit['fg']);
    }

    public function testNoColourCanMakeAnyThemeUnreadable(): void
    {
        $colours = ['#000000', '#ffffff', '#808080', '#ff0000', '#00ff00', '#0000ff', '#ffff00', '#ff00ff', '#00ffff', '#7f3fbf', '#c0ffee', '#123456'];
        foreach (Themes::BACKGROUNDS as $theme => $backgrounds) {
            foreach ($backgrounds as $scheme => $background) {
                foreach ($colours as $colour) {
                    $fit = Accent::fit($colour, $background);
                    $where = "$colour in $theme ($scheme)";
                    self::assertGreaterThanOrEqual(Accent::MIN_CONTRAST, Accent::contrast($fit['accent'], $background), "links: $where");
                    self::assertGreaterThanOrEqual(Accent::MIN_CONTRAST, Accent::contrast($fit['accent'], $fit['fg']), "buttons: $where");
                }
            }
        }
    }

    public function testTheBackgroundsMatchTheThemes(): void
    {
        self::assertSame(array_keys(Themes::ALL), array_keys(Themes::BACKGROUNDS));
        foreach (Themes::BACKGROUNDS as $theme => $expected) {
            $css = (string) file_get_contents(dirname(__DIR__, 2) . "/themes/$theme/theme.css");
            preg_match_all('~--bg:\s*(#[0-9a-fA-F]{3,6})\s*;~', $css, $found);
            self::assertCount(2, $found[1], "$theme sets --bg for light and dark");
            self::assertSame([$expected['light'], $expected['dark']], array_map(static fn(string $c): ?string => Accent::parse($c), $found[1]), $theme);
        }
    }
}
