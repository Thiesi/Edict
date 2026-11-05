<?php

declare(strict_types=1);

namespace Edict\Tests\Http;

final class HomeTest extends AppTestCase
{
    public function testHomeRendersThroughTwig(): void
    {
        $response = $this->get('/');

        self::assertSame(200, $response->getStatusCode());
        self::assertStringStartsWith('text/html', $response->getHeaderLine('Content-Type'));
        self::assertStringContainsString('<h1>Hello, Edict</h1>', (string) $response->getBody());
    }

    public function testUnknownPathIs404(): void
    {
        self::assertSame(404, $this->get('/no-such-page')->getStatusCode());
    }
}
