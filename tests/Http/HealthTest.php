<?php

declare(strict_types=1);

namespace Edict\Tests\Http;

use Edict\Config;
use Edict\Http\HealthAction;
use PHPUnit\Framework\Attributes\Group;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

final class HealthTest extends AppTestCase
{
    public function testHealthyWithoutDatabase(): void
    {
        $response = $this->get('/healthz', ['DATABASE_URL' => '']);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertSame(
            ['status' => 'ok', 'release' => 'unreleased', 'checks' => ['app' => 'ok', 'database' => 'not configured', 'templates' => 'ok']],
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

    public function testDegradedWhenTemplatesFail(): void
    {
        $config = Config::fromEnvironment('/x', ['APP_ENV' => 'test']);
        $action = new HealthAction($config, new Environment(new ArrayLoader()));

        $response = $action(
            (new ServerRequestFactory())->createServerRequest('GET', '/healthz'),
            (new ResponseFactory())->createResponse(),
        );

        self::assertSame(503, $response->getStatusCode());
        self::assertStringContainsString('"templates":"unavailable"', (string) $response->getBody());
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
