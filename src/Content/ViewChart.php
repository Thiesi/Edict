<?php

declare(strict_types=1);

namespace Edict\Content;

/**
 * The dashboard's bar chart of views per day, worked out here and drawn by the
 * template as an inline SVG: no chart library, no script, nothing styled inline.
 */
final class ViewChart
{
    public const WIDTH = 300;
    public const HEIGHT = 72;
    /** The gap between two bars, in the chart's own units. */
    private const GAP = 2;
    /** The rounding of a bar's top end. */
    private const RADIUS = 2;

    /**
     * @param list<array{day: string, views: int}> $days oldest first, as Views::daily() gives them
     *
     * @return array{
     *     total: int,
     *     peak: int,
     *     first: string,
     *     last: string,
     *     width: int,
     *     height: int,
     *     bars: list<array{day: string, label: string, views: int, x: float, slot: float, path: string|null}>
     * }
     */
    public static function build(array $days): array
    {
        $count = max(1, count($days));
        $slot = self::WIDTH / $count;
        $width = max(1.0, $slot - self::GAP);
        $peak = max([0, ...array_column($days, 'views')]);

        $bars = [];
        foreach ($days as $i => $day) {
            $x = $i * $slot;
            // Any view at all stays visible, however small beside the busiest day.
            $height = $peak === 0 || $day['views'] === 0 ? 0.0 : max(1.0, $day['views'] / $peak * self::HEIGHT);
            $bars[] = [
                'day' => $day['day'],
                'label' => self::label($day['day']),
                'views' => $day['views'],
                'x' => round($x, 2),
                'slot' => round($slot, 2),
                'path' => $height === 0.0 ? null : self::bar($x + self::GAP / 2, $width, $height),
            ];
        }

        return [
            'total' => array_sum(array_column($days, 'views')),
            'peak' => $peak,
            'first' => $days === [] ? '' : self::label($days[0]['day']),
            'last' => $days === [] ? '' : self::label($days[count($days) - 1]['day']),
            'width' => self::WIDTH,
            'height' => self::HEIGHT,
            'bars' => $bars,
        ];
    }

    /**
     * A bar standing on the baseline, its top corners rounded.
     */
    private static function bar(float $x, float $width, float $height): string
    {
        $r = min(self::RADIUS, $width / 2, $height);
        $top = self::HEIGHT - $height;
        $f = static fn(float $n): string => rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.');

        return 'M' . $f($x) . ' ' . self::HEIGHT
            . 'V' . $f($top + $r)
            . 'Q' . $f($x) . ' ' . $f($top) . ' ' . $f($x + $r) . ' ' . $f($top)
            . 'H' . $f($x + $width - $r)
            . 'Q' . $f($x + $width) . ' ' . $f($top) . ' ' . $f($x + $width) . ' ' . $f($top + $r)
            . 'V' . self::HEIGHT . 'Z';
    }

    /**
     * "15 Nov", for a day given as "2026-11-15".
     */
    private static function label(string $day): string
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $day);

        return $date === false ? $day : $date->format('j M');
    }
}
