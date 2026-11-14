<?php

declare(strict_types=1);

namespace Edict\Site;

/**
 * The admin's own accent colour, made safe for every theme. The hue and
 * saturation stay; only the lightness moves, and only as far as needed for links
 * to reach WCAG AA (4.5:1) on the theme's background, and for text on an accent
 * button to reach it too. So no choice can make the site unreadable.
 */
final class Accent
{
    public const MIN_CONTRAST = 4.5;
    private const DARK_TEXT = '#111111';
    private const LIGHT_TEXT = '#ffffff';

    /**
     * @return string|null "#rrggbb", or null if it isn't a colour
     */
    public static function parse(string $value): ?string
    {
        $value = strtolower(trim($value));
        if (preg_match('~^#?([0-9a-f]{3}|[0-9a-f]{6})$~D', $value, $match) !== 1) {
            return null;
        }
        $hex = $match[1];
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }

        return '#' . $hex;
    }

    /**
     * @return array{accent: string, fg: string, adjusted: bool}
     */
    public static function fit(string $colour, string $background): array
    {
        $colour = self::parse($colour) ?? throw new \InvalidArgumentException('Not a colour: ' . $colour);
        $background = self::parse($background) ?? throw new \InvalidArgumentException('Not a colour: ' . $background);
        [$h, $s, $l] = self::toHsl($colour);
        // Away from the background: darker on light themes, lighter on dark ones.
        $step = self::luminance($background) > 0.18 ? -0.01 : 0.01;

        for ($i = 0; $i <= 100; ++$i) {
            $candidate = $i === 0 ? $colour : self::fromHsl($h, $s, max(0.0, min(1.0, $l + $step * $i)));
            $fg = self::contrast($candidate, self::LIGHT_TEXT) >= self::contrast($candidate, self::DARK_TEXT) ? self::LIGHT_TEXT : self::DARK_TEXT;
            if (self::contrast($candidate, $background) >= self::MIN_CONTRAST && self::contrast($candidate, $fg) >= self::MIN_CONTRAST) {
                return ['accent' => $candidate, 'fg' => $fg, 'adjusted' => $i > 0];
            }
        }

        // Unreachable for real backgrounds: black or white always passes. A safe answer anyway.
        $fallback = $step < 0 ? '#000000' : '#ffffff';

        return ['accent' => $fallback, 'fg' => $step < 0 ? self::LIGHT_TEXT : self::DARK_TEXT, 'adjusted' => true];
    }

    public static function contrast(string $a, string $b): float
    {
        $la = self::luminance($a);
        $lb = self::luminance($b);

        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    private static function luminance(string $hex): float
    {
        $channels = [];
        foreach ([1, 3, 5] as $offset) {
            $c = hexdec(substr($hex, $offset, 2)) / 255;
            $channels[] = $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        }

        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    }

    /**
     * @return array{float, float, float}
     */
    private static function toHsl(string $hex): array
    {
        $r = hexdec(substr($hex, 1, 2)) / 255;
        $g = hexdec(substr($hex, 3, 2)) / 255;
        $b = hexdec(substr($hex, 5, 2)) / 255;
        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        $l = ($max + $min) / 2;
        if ($max === $min) {
            return [0.0, 0.0, $l];
        }
        $d = $max - $min;
        $s = $l > 0.5 ? $d / (2 - $max - $min) : $d / ($max + $min);
        $h = match ($max) {
            $r => ($g - $b) / $d + ($g < $b ? 6 : 0),
            $g => ($b - $r) / $d + 2,
            default => ($r - $g) / $d + 4,
        };

        return [$h / 6, $s, $l];
    }

    private static function fromHsl(float $h, float $s, float $l): string
    {
        if ($s === 0.0) {
            $r = $g = $b = $l;
        } else {
            $q = $l < 0.5 ? $l * (1 + $s) : $l + $s - $l * $s;
            $p = 2 * $l - $q;
            $r = self::hue($p, $q, $h + 1 / 3);
            $g = self::hue($p, $q, $h);
            $b = self::hue($p, $q, $h - 1 / 3);
        }

        return sprintf('#%02x%02x%02x', (int) round($r * 255), (int) round($g * 255), (int) round($b * 255));
    }

    private static function hue(float $p, float $q, float $t): float
    {
        if ($t < 0) {
            ++$t;
        }
        if ($t > 1) {
            --$t;
        }

        return match (true) {
            $t < 1 / 6 => $p + ($q - $p) * 6 * $t,
            $t < 1 / 2 => $q,
            $t < 2 / 3 => $p + ($q - $p) * (2 / 3 - $t) * 6,
            default => $p,
        };
    }
}
