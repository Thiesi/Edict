<?php

declare(strict_types=1);

namespace Edict\Tests\Media;

use Edict\Media\ToneMap;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ToneMapTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function transfers(): iterable
    {
        yield 'PQ' => [ToneMap::PQ];
        yield 'HLG' => [ToneMap::HLG];
    }

    public function testPqRoundTrips(): void
    {
        foreach ([0.5, 100.0, 203.0, 1000.0, 10000.0] as $nits) {
            self::assertEqualsWithDelta($nits, ToneMap::pqToNits(ToneMap::nitsToPq($nits)), $nits * 1e-6);
        }
    }

    public function testPqBrightnessLandsWhereAnOrdinaryScreenExpectsIt(): void
    {
        // Mid-grey (18 % of reference white) stays mid-grey, untouched by the roll-off.
        self::assertEqualsWithDelta(0.18 ** (1 / 2.4), self::grey(ToneMap::PQ, ToneMap::nitsToPq(0.18 * 203)), 0.005);
        // Reference white is bright but leaves room for highlights.
        self::assertEqualsWithDelta(0.90, self::grey(ToneMap::PQ, ToneMap::nitsToPq(203)), 0.02);
        // The source peak, and anything brighter, is white.
        self::assertEqualsWithDelta(1.0, self::grey(ToneMap::PQ, ToneMap::nitsToPq(1000)), 0.001);
        self::assertEqualsWithDelta(1.0, self::grey(ToneMap::PQ, 1.0), 0.001);
    }

    public function testHlgReferenceWhiteMatchesPq(): void
    {
        // BT.2408: 75 % HLG is the same reference white as 203 nits PQ.
        self::assertEqualsWithDelta(self::grey(ToneMap::PQ, ToneMap::nitsToPq(203)), self::grey(ToneMap::HLG, 0.75), 0.01);
        self::assertEqualsWithDelta(1.0, self::grey(ToneMap::HLG, 1.0), 0.001);
    }

    #[DataProvider('transfers')]
    public function testGreysStayGreyAndGetBrighterInOrder(string $transfer): void
    {
        $previous = -1.0;
        for ($step = 0; $step <= 100; $step++) {
            $signal = $step / 100;
            [$r, $g, $b] = ToneMap::map($transfer, $signal, $signal, $signal);
            self::assertEqualsWithDelta($r, $g, 0.002, "neutral at $signal");
            self::assertEqualsWithDelta($g, $b, 0.002, "neutral at $signal");
            self::assertGreaterThanOrEqual($previous, $g, "never darker at $signal");
            $previous = $g;
        }
        self::assertSame([0.0, 0.0, 0.0], ToneMap::map($transfer, 0.0, 0.0, 0.0));
    }

    #[DataProvider('transfers')]
    public function testEveryColourFitsAndKeepsItsLeadingChannel(string $transfer): void
    {
        $steps = [0.0, 0.2, 0.4, 0.6, 0.8, 1.0];
        foreach ($steps as $r) {
            foreach ($steps as $g) {
                foreach ($steps as $b) {
                    $out = ToneMap::map($transfer, $r, $g, $b);
                    foreach ($out as $channel) {
                        self::assertGreaterThanOrEqual(0.0, $channel);
                        self::assertLessThanOrEqual(1.0, $channel);
                    }
                }
            }
        }
        // Saturated colours don't change hue family.
        $red = ToneMap::map($transfer, 0.7, 0.1, 0.1);
        self::assertSame(0, array_search(max($red), $red, true));
        $blue = ToneMap::map($transfer, 0.1, 0.1, 0.7);
        self::assertSame(2, array_search(max($blue), $blue, true));
    }

    public function testTheCubeHasEveryPointInOrder(): void
    {
        $lines = explode("\n", rtrim(ToneMap::cube(ToneMap::PQ)));

        self::assertContains('LUT_3D_SIZE ' . ToneMap::SIZE, $lines);
        $data = array_values(array_filter($lines, static fn(string $line): bool => preg_match('/^[0-9]/', $line) === 1));
        self::assertCount(ToneMap::SIZE ** 3, $data);
        self::assertSame('0.000000 0.000000 0.000000', $data[0]);
        self::assertSame('1.000000 1.000000 1.000000', $data[count($data) - 1]);
        // Red changes fastest: the second point is a dark red.
        [$r, $g, $b] = array_map(floatval(...), explode(' ', $data[1]));
        self::assertGreaterThan(2 * max($g, $b), $r);
    }

    public function testOnlyHdrTransfersAreAccepted(): void
    {
        self::assertTrue(ToneMap::isHdr('smpte2084'));
        self::assertTrue(ToneMap::isHdr('arib-std-b67'));
        self::assertFalse(ToneMap::isHdr('bt709'));
        self::assertFalse(ToneMap::isHdr(null));

        $this->expectException(\InvalidArgumentException::class);
        ToneMap::map('bt709', 0.5, 0.5, 0.5);
    }

    private static function grey(string $transfer, float $signal): float
    {
        return ToneMap::map($transfer, $signal, $signal, $signal)[1];
    }
}
