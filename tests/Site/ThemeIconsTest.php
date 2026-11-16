<?php

declare(strict_types=1);

namespace Edict\Tests\Site;

use Edict\Site\Themes;
use PHPUnit\Framework\TestCase;

final class ThemeIconsTest extends TestCase
{
    private string $public;

    protected function setUp(): void
    {
        $this->public = sys_get_temp_dir() . '/edict-theme-icons-' . bin2hex(random_bytes(4));
        mkdir($this->public . '/assets/themes/quiet', 0o777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->public . '/assets/themes/*/*') ?: [] as $file) {
            unlink($file);
        }
        foreach (glob($this->public . '/assets/themes/*') ?: [] as $dir) {
            rmdir($dir);
        }
        rmdir($this->public . '/assets/themes');
        rmdir($this->public . '/assets');
        rmdir($this->public);
    }

    public function testAThemeWithAllThreeFilesHasItsOwnIcon(): void
    {
        foreach (Themes::ICON_FILES as $file) {
            touch($this->public . '/assets/themes/quiet/' . $file);
        }

        self::assertSame([
            'svg' => '/assets/themes/quiet/favicon.svg',
            'png' => '/assets/themes/quiet/icon-192.png',
            'touch' => '/assets/themes/quiet/apple-touch-icon.png',
        ], Themes::icons('quiet', $this->public));
    }

    public function testAPartOfAnIconIsNoIcon(): void
    {
        touch($this->public . '/assets/themes/quiet/favicon.svg');

        self::assertNull(Themes::icons('quiet', $this->public), 'Edict’s seal rather than half a set');
    }

    public function testOnlyKnownThemesAreLookedUp(): void
    {
        self::assertNull(Themes::icons('../../etc', $this->public));
        self::assertNull(Themes::icons('nonsense', $this->public));
    }

    public function testEveryShippedThemeIconIsComplete(): void
    {
        $public = dirname(__DIR__, 2) . '/public';
        $this->addToAssertionCount(1); // Holds even before any theme ships an icon.
        foreach (array_keys(Themes::ALL) as $id) {
            $dir = $public . '/assets/themes/' . $id;
            if (!is_dir($dir)) {
                continue;
            }
            self::assertNotNull(Themes::icons($id, $public), "$id ships part of an icon set");
            $svg = (string) file_get_contents($dir . '/favicon.svg');
            self::assertStringStartsWith('<svg', ltrim(preg_replace('~^<\?xml[^>]*>~', '', $svg) ?? ''), "$id: an SVG");
            self::assertStringNotContainsStringIgnoringCase('<script', $svg, "$id: no script in an icon");
            self::assertDoesNotMatchRegularExpression('~(href|src)\s*=\s*["\']https?:~i', $svg, "$id: nothing from elsewhere");
            foreach (['icon-192.png' => 192, 'apple-touch-icon.png' => 180] as $file => $size) {
                $info = getimagesize($dir . '/' . $file);
                self::assertNotFalse($info, "$id/$file is an image");
                self::assertSame([$size, $size], [$info[0], $info[1]], "$id/$file is {$size}×{$size}");
            }
        }
    }
}
