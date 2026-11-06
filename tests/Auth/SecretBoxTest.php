<?php

declare(strict_types=1);

namespace Edict\Tests\Auth;

use Edict\Auth\SecretBox;
use PHPUnit\Framework\TestCase;

final class SecretBoxTest extends TestCase
{
    public function testRoundTripWithFreshIv(): void
    {
        $box = new SecretBox(str_repeat('k', 32));

        $first = $box->seal('secret');

        self::assertSame('secret', $box->open($first));
        self::assertNotSame($first, $box->seal('secret'));
        self::assertStringNotContainsString('secret', (string) base64_decode($first, true));
    }

    public function testWrongKeyFails(): void
    {
        $sealed = (new SecretBox(str_repeat('a', 32)))->seal('secret');

        $this->expectException(\RuntimeException::class);
        (new SecretBox(str_repeat('b', 32)))->open($sealed);
    }

    public function testTamperedDataFails(): void
    {
        $box = new SecretBox(str_repeat('k', 32));
        $raw = (string) base64_decode($box->seal('secret'), true);
        $raw[strlen($raw) - 1] = $raw[strlen($raw) - 1] ^ "\x01";

        $this->expectException(\RuntimeException::class);
        $box->open(base64_encode($raw));
    }
}
