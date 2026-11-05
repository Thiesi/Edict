<?php

declare(strict_types=1);

namespace Edict\Tests\Database;

use Edict\Database\ConnectionFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ConnectionFactoryTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function badUrls(): iterable
    {
        yield 'mysql scheme' => ['mysql://u:p@localhost/db'];
        yield 'no database' => ['pgsql://u:p@localhost:5432/'];
        yield 'garbage' => ['not a url at all'];
    }

    #[DataProvider('badUrls')]
    public function testRejectsBadUrls(string $url): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ConnectionFactory::fromUrl($url);
    }
}
