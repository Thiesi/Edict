<?php

declare(strict_types=1);

namespace Edict\Tests\Http;

use PHPUnit\Framework\Attributes\Group;

final class HealthTest extends AppTestCase
{
    public function testHealthyWithoutDatabase(): void
    {
        $response = $this->get('/healthz', ['DATABASE_URL' => '']);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertSame(
            ['status' => 'ok', 'release' => 'unreleased', 'checks' => ['app' => 'ok', 'database' => 'not configured']],
            json_decode((string) $response->getBody(), true),
        );
    }

    public function testDegradedWhenDatabaseUnreachable(): void
    {
        // Port 1 on localhost: nothing listens, connection is refused immediately.
        $response = $this->get('/healthz', ['DATABASE_URL' => 'pgsql://nobody:secret@127.0.0.1:1/nothing']);
        $body = (string) $response->getBody();

        self::assertSame(503, $response->getStatusCode());
        self::assertStringContainsString('"database":"unavailable"', $body);
        self::assertStringNotContainsString('secret', $body);
    }

    #[Group('db')]
    public function testHealthyWithRealDatabase(): void
    {
        $url = getenv('TEST_DATABASE_URL');
        if (!is_string($url) || $url === '') {
            self::markTestSkipped('TEST_DATABASE_URL not set');
        }

        $response = $this->get('/healthz', ['DATABASE_URL' => $url]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
    }
}
