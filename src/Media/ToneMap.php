<?php

declare(strict_types=1);

namespace Edict\Media;

/**
 * Turns HDR video into ordinary video by way of a 3D lookup table, which ffmpeg's built-in
 * lut3d filter applies. The host's ffmpeg has no zscale or libplacebo, the usual tools.
 *
 * The table maps BT.2020 RGB, encoded with PQ (HDR10, HDR10+) or HLG (iPhones), to BT.709
 * RGB for an ordinary screen. Brightness follows ITU-R BT.2390: 400 nits becomes the
 * screen's white, and anything brighter is rolled off into the top of the range instead
 * of clipping. That puts the HDR reference white of 203 nits (BT.2408), where white walls
 * and paper sit, about a stop below the screen's white, leaving room for lamps and sky,
 * close to how phones themselves turn their HDR footage into ordinary pictures. The curve follows each colour's brightest
 * channel, so bright colours keep their saturation, and colours the smaller gamut can't
 * show are desaturated towards their own grey.
 */
final class ToneMap
{
    /** Points per side. The input is PQ or HLG encoded, so the steps are perceptually even. */
    public const SIZE = 33;

    public const PQ = 'smpte2084';
    public const HLG = 'arib-std-b67';

    /** Nits that become the screen's white. At 203, white walls and lamps came out the same white. */
    private const TARGET_WHITE = 400.0;

    /** BT.2408's reference white: paper, a white wall. */
    public const REFERENCE_WHITE = 203.0;

    /** Brightest source nits kept apart; what most phones master to. Brighter is rolled off to white. */
    private const SOURCE_PEAK = 1000.0;

    // ST 2084 (PQ).
    private const M1 = 2610 / 16384;
    private const M2 = 2523 / 4096 * 128;
    private const C1 = 3424 / 4096;
    private const C2 = 2413 / 4096 * 32;
    private const C3 = 2392 / 4096 * 32;
    private const PQ_PEAK = 10000.0;

    // BT.2100 HLG, for a 1000 nit display.
    private const HLG_A = 0.17883277;
    private const HLG_B = 0.28466892;
    private const HLG_C = 0.55991073;
    private const HLG_PEAK = 1000.0;
    private const HLG_GAMMA = 1.2;

    /** Luminance weights of BT.2020 primaries. */
    private const Y2020 = [0.2627, 0.6780, 0.0593];

    /** Linear BT.2020 RGB to linear BT.709 RGB (BT.2087). */
    private const TO_709 = [
        [1.660491, -0.587641, -0.072850],
        [-0.124550, 1.132900, -0.008349],
        [-0.018151, -0.100579, 1.118730],
    ];

    /** BT.1886: what an ordinary screen does with a BT.709 signal. */
    private const SCREEN_GAMMA = 2.4;

    public static function isHdr(?string $transfer): bool
    {
        return $transfer === self::PQ || $transfer === self::HLG;
    }

    /**
     * The table in the .cube format, red changing fastest.
     */
    public static function cube(string $transfer): string
    {
        $last = self::SIZE - 1;
        $lines = ['# Edict: ' . $transfer . ' BT.2020 to BT.709', 'LUT_3D_SIZE ' . self::SIZE];
        for ($b = 0; $b <= $last; $b++) {
            for ($g = 0; $g <= $last; $g++) {
                for ($r = 0; $r <= $last; $r++) {
                    $lines[] = vsprintf('%.6f %.6f %.6f', self::map($transfer, $r / $last, $g / $last, $b / $last));
                }
            }
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * One colour: full range HDR signal in, full range BT.709 signal out.
     *
     * @return array{float, float, float}
     */
    public static function map(string $transfer, float $r, float $g, float $b): array
    {
        $nits = match ($transfer) {
            self::PQ => [self::pqToNits($r), self::pqToNits($g), self::pqToNits($b)],
            self::HLG => self::hlgToNits($r, $g, $b),
            default => throw new \InvalidArgumentException("Not an HDR transfer: $transfer"),
        };

        $linear = [];
        foreach (self::TO_709 as $row) {
            $linear[] = $row[0] * $nits[0] + $row[1] * $nits[1] + $row[2] * $nits[2];
        }

        // Colours outside BT.709 lose saturation towards their own grey until they fit.
        $grey = self::luminance($nits);
        if ($grey <= 0.0) {
            return [0.0, 0.0, 0.0];
        }
        $keep = 1.0;
        foreach ($linear as $channel) {
            if ($channel < 0.0) {
                $keep = min($keep, $grey / ($grey - $channel));
            }
        }
        $linear = array_map(static fn(float $channel): float => max(0.0, $grey + $keep * ($channel - $grey)), $linear);

        // The curve works on the brightest channel, as HDR10+ itself does: a bright colour
        // gets darker rather than paler, so neon and backlights stay saturated.
        $brightest = max($linear);
        $scale = self::rollOff($brightest) / $brightest / self::TARGET_WHITE;

        $out = [];
        foreach ($linear as $channel) {
            $out[] = min(1.0, $channel * $scale) ** (1 / self::SCREEN_GAMMA);
        }

        return [$out[0], $out[1], $out[2]];
    }

    public static function pqToNits(float $signal): float
    {
        $power = max(0.0, $signal) ** (1 / self::M2);

        return self::PQ_PEAK * (max($power - self::C1, 0.0) / (self::C2 - self::C3 * $power)) ** (1 / self::M1);
    }

    public static function nitsToPq(float $nits): float
    {
        $power = (max(0.0, $nits) / self::PQ_PEAK) ** self::M1;

        return ((self::C1 + self::C2 * $power) / (1 + self::C3 * $power)) ** self::M2;
    }

    /**
     * HLG signal to display light, including the system gamma that HLG leaves to the display.
     *
     * @return array{float, float, float}
     */
    private static function hlgToNits(float $r, float $g, float $b): array
    {
        $scene = [self::hlgToScene($r), self::hlgToScene($g), self::hlgToScene($b)];
        $luminance = self::luminance($scene);
        if ($luminance <= 0.0) {
            return [0.0, 0.0, 0.0];
        }
        $gain = self::HLG_PEAK * $luminance ** (self::HLG_GAMMA - 1);

        return [$scene[0] * $gain, $scene[1] * $gain, $scene[2] * $gain];
    }

    private static function hlgToScene(float $signal): float
    {
        $signal = min(1.0, max(0.0, $signal));

        return $signal <= 0.5
            ? $signal * $signal / 3
            : (exp(($signal - self::HLG_C) / self::HLG_A) + self::HLG_B) / 12;
    }

    /**
     * @param array{float, float, float} $rgb
     */
    private static function luminance(array $rgb): float
    {
        return self::Y2020[0] * $rgb[0] + self::Y2020[1] * $rgb[1] + self::Y2020[2] * $rgb[2];
    }

    /**
     * BT.2390's roll-off, worked in PQ: unchanged up to a knee, then a curve that reaches the
     * target white exactly at the source peak.
     */
    private static function rollOff(float $nits): float
    {
        $sourcePeak = self::nitsToPq(self::SOURCE_PEAK);
        $black = self::nitsToPq(0.0);
        $range = $sourcePeak - $black;
        $signal = min(1.0, (self::nitsToPq($nits) - $black) / $range);
        $target = (self::nitsToPq(self::TARGET_WHITE) - $black) / $range;
        $knee = 1.5 * $target - 0.5;

        if ($signal > $knee) {
            $t = ($signal - $knee) / (1 - $knee);
            $t2 = $t * $t;
            $t3 = $t2 * $t;
            $signal = (2 * $t3 - 3 * $t2 + 1) * $knee + ($t3 - 2 * $t2 + $t) * (1 - $knee) + (-2 * $t3 + 3 * $t2) * $target;
        }

        return self::pqToNits($signal * $range + $black);
    }
}
