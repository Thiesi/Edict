<?php

declare(strict_types=1);

namespace Edict\Site;

use Edict\Database\Row;
use Edict\Media\IconProcessor;
use Edict\Media\MediaStore;

/**
 * What the admin adds on top of the chosen theme: an accent colour, a logo and the
 * site icon. Each is optional; without them the theme looks as it was designed.
 */
final readonly class Look
{
    private const LOGO_WIDTHS = [480, 960];

    public function __construct(private Settings $settings, private \PDO $pdo) {}

    /**
     * The accent for this theme, in light and dark, or null for the theme's own.
     *
     * @return array{light: array{accent: string, fg: string, adjusted: bool}, dark: array{accent: string, fg: string, adjusted: bool}}|null
     */
    public function accent(string $theme): ?array
    {
        $colour = $this->settings->site()->accent;
        $backgrounds = Themes::BACKGROUNDS[$theme] ?? null;
        if ($colour === '' || $backgrounds === null) {
            return null;
        }

        return ['light' => Accent::fit($colour, $backgrounds['light']), 'dark' => Accent::fit($colour, $backgrounds['dark'])];
    }

    /**
     * The same colour fitted against the header, for themes whose header has a
     * background of its own: there the page's version can be unreadable.
     *
     * @return array{light: string, dark: string}|null
     */
    public function headerAccent(string $theme): ?array
    {
        $colour = $this->settings->site()->accent;
        $backgrounds = Themes::headerBackgrounds($theme);
        if ($colour === '' || $backgrounds === null) {
            return null;
        }

        return ['light' => Accent::fit($colour, $backgrounds['light'])['accent'], 'dark' => Accent::fit($colour, $backgrounds['dark'])['accent']];
    }

    /**
     * Loaded after the theme, so it wins. Plain custom properties: no inline styles,
     * which the CSP forbids. Without an accent of the admin's own it sets nothing,
     * and the theme keeps its colours, the header's included.
     */
    public function accentCss(string $theme): string
    {
        $accent = $this->accent($theme);
        $header = $this->headerAccent($theme);
        if ($accent === null || $header === null) {
            return "/* The theme's own accent. */\n";
        }
        $rules = static fn(array $c): string => "--accent: {$c['accent']}; --accent-fg: {$c['fg']}; --focus: {$c['accent']};";

        return ":root { {$rules($accent['light'])} }\n"
            . ":root { --header-accent: {$header['light']}; }\n"
            . "@media (prefers-color-scheme: dark) { :root { {$rules($accent['dark'])} } :root { --header-accent: {$header['dark']}; } }\n";
    }

    /**
     * Changes whenever the colour does, so the stylesheet can be cached for good.
     */
    public function accentVersion(): ?string
    {
        $colour = $this->settings->site()->accent;

        return $colour === '' ? null : substr(hash('sha256', $colour), 0, 12);
    }

    /**
     * @return array{src: string, srcset: string, width: int, height: int}|null
     */
    public function logo(): ?array
    {
        $variants = $this->variants($this->settings->site()->logo);
        $webp = [];
        foreach (self::LOGO_WIDTHS as $width) {
            if (isset($variants["w$width-webp"])) {
                $webp[$width] = $variants["w$width-webp"];
            }
        }
        // WebP keeps transparency, which a logo usually needs; the full version is the fallback.
        $first = reset($webp) ?: ($variants['full'] ?? null);
        if ($first === null) {
            return null;
        }
        $srcset = [];
        foreach ($webp as $width => $variant) {
            $srcset[] = $variant['url'] . ' ' . $width . 'w';
        }

        return ['src' => $first['url'], 'srcset' => implode(', ', $srcset), 'width' => $first['width'], 'height' => $first['height']];
    }

    /**
     * The admin's icon in the sizes browsers ask for, or null for Edict's own.
     *
     * @return array<int, string>|null size => URL
     */
    public function icons(): ?array
    {
        $variants = $this->variants($this->settings->site()->favicon);
        $icons = [];
        foreach (IconProcessor::SIZES as $size) {
            $variant = $variants[IconProcessor::preset($size)] ?? null;
            if ($variant === null) {
                return null; // Not made yet: Edict's icon until all are there.
            }
            $icons[$size] = $variant['url'];
        }

        return $icons;
    }

    /**
     * @return array<string, array{url: string, width: int, height: int}>
     */
    private function variants(int $mediaId): array
    {
        if ($mediaId === 0) {
            return [];
        }
        $stmt = $this->pdo->prepare(
            "SELECT m.sha256, v.preset, v.format, v.width, v.height
             FROM media m JOIN media_variant v ON v.media_id = m.id
             WHERE m.id = ? AND m.kind = 'image'",
        );
        $stmt->execute([$mediaId]);
        $variants = [];
        foreach ($stmt->fetchAll() as $row) {
            if (is_array($row)) {
                $preset = Row::str($row, 'preset');
                $variants[$preset] = [
                    'url' => '/media/' . MediaStore::relativeVariantPath(Row::str($row, 'sha256'), $preset, Row::str($row, 'format')),
                    'width' => Row::int($row, 'width'),
                    'height' => Row::int($row, 'height'),
                ];
            }
        }

        return $variants;
    }
}
