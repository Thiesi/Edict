<?php

declare(strict_types=1);

namespace Edict\Tests\Auth;

use Edict\Auth\Totp;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TotpTest extends TestCase
{
    private const RFC_SECRET = '12345678901234567890';

    /**
     * RFC 6238 appendix B SHA-1 vectors, reduced to 6 digits.
     *
     * @return iterable<array{int, string}>
     */
    public static function rfcVectors(): iterable
    {
        yield [59, '287082'];
        yield [1111111109, '081804'];
        yield [1111111111, '050471'];
        yield [1234567890, '005924'];
        yield [2000000000, '279037'];
    }

    #[DataProvider('rfcVectors')]
    public function testRfcVectors(int $time, string $expected): void
    {
        self::assertSame($expected, Totp::code(self::RFC_SECRET, intdiv($time, 30)));
    }

    public function testMatchesCurrentAndAdjacentStepsOnly(): void
    {
        $now = 1_000_000_020;
        $step = intdiv($now, 30);

        foreach ([$step - 1, $step, $step + 1] as $candidate) {
            self::assertSame($candidate, Totp::matchStep(self::RFC_SECRET, Totp::code(self::RFC_SECRET, $candidate), $now));
        }
        self::assertNull(Totp::matchStep(self::RFC_SECRET, Totp::code(self::RFC_SECRET, $step + 2), $now));
        self::assertNull(Totp::matchStep(self::RFC_SECRET, Totp::code(self::RFC_SECRET, $step - 2), $now));
    }

    public function testRejectsMalformedCodes(): void
    {
        foreach (['', '12345', '1234567', 'abcdef', "12345\n"] as $code) {
            self::assertNull(Totp::matchStep(self::RFC_SECRET, $code, 1_000_000_000));
        }
    }

    public function testBase32MatchesRfc4648(): void
    {
        self::assertSame('MZXW6YTB', Totp::base32('fooba'));
        self::assertSame('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', Totp::base32(self::RFC_SECRET));
    }

    public function testProvisioningUriEscapesAccount(): void
    {
        $uri = Totp::provisioningUri(self::RFC_SECRET, 'a b');

        self::assertStringStartsWith('otpauth://totp/Edict:a%20b?secret=GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ&issuer=Edict', $uri);
    }
}
