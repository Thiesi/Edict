<?php

declare(strict_types=1);

namespace Edict\Tests;

use DI\Container;
use Edict\Auth\OAuth\HttpClient;
use Edict\Clock;
use Edict\Database\ConnectionFactory;
use Edict\Database\Migrator;
use Edict\Kernel;
use Edict\Services;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Base for tests against a real PostgreSQL (TEST_DATABASE_URL). Skipped without
 * one. The schema is rebuilt once per run and all tables are emptied per test.
 */
#[Group('db')]
abstract class DbTestCase extends TestCase
{
    protected const ORIGIN = 'http://localhost:8080';

    private static bool $schemaReady = false;

    protected FixedClock $clock;
    protected FakeHttpClient $http;
    protected \PDO $pdo;

    /** @var App<Container> */
    protected App $app;

    protected function setUp(): void
    {
        $url = getenv('TEST_DATABASE_URL');
        if (!is_string($url) || $url === '') {
            self::markTestSkipped('TEST_DATABASE_URL not set');
        }

        $this->pdo = self::connectToTestDatabase($url);
        if (!self::$schemaReady) {
            $this->pdo->exec('DROP SCHEMA public CASCADE; CREATE SCHEMA public');
            (new Migrator($this->pdo, dirname(__DIR__) . '/migrations'))->migrate();
            self::$schemaReady = true;
        }
        $this->pdo->exec('TRUNCATE app_user, session, login_attempt, webauthn_used_challenge, article, tag RESTART IDENTITY CASCADE');

        $this->clock = new FixedClock(1_800_000_000);
        $this->http = new FakeHttpClient();
        $this->app = Kernel::boot(
            dirname(__DIR__),
            [
                'APP_ENV' => 'test',
                'DATABASE_URL' => $url,
                'APP_URL' => self::ORIGIN,
                'GOOGLE_OIDC_CLIENT_ID' => 'google-client',
                'GOOGLE_OIDC_CLIENT_SECRET' => 'google-secret',
                'TWITCH_OIDC_CLIENT_ID' => 'twitch-client',
                'TWITCH_OIDC_CLIENT_SECRET' => 'twitch-secret',
            ],
            [Clock::class => $this->clock, \PDO::class => $this->pdo, HttpClient::class => $this->http],
        );
    }

    /**
     * Tests wipe the database, so they refuse anything not named *_test.
     */
    public static function connectToTestDatabase(string $url): \PDO
    {
        $path = parse_url($url, PHP_URL_PATH);
        if (!is_string($path) || !str_ends_with($path, '_test')) {
            throw new \RuntimeException('Refusing to run: TEST_DATABASE_URL must point at a database named *_test');
        }

        return ConnectionFactory::fromUrl($url);
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    protected function service(string $class): object
    {
        return (new Services($this->app->getContainer()))->get($class);
    }

    /**
     * @param array<string, string> $cookies
     * @param array<string, string> $headers
     */
    protected function get(string $path, array $cookies = [], array $headers = []): ResponseInterface
    {
        return $this->request('GET', $path, null, $cookies, $headers);
    }

    /**
     * @param array<string, string> $body
     * @param array<string, string> $cookies
     * @param array<string, string> $headers
     */
    protected function post(string $path, array $body = [], array $cookies = [], array $headers = []): ResponseInterface
    {
        return $this->request('POST', $path, $body, $cookies, $headers);
    }

    /**
     * @param array<string, string>|null $body
     * @param array<string, string> $cookies
     * @param array<string, string> $headers
     */
    private function request(string $method, string $path, ?array $body, array $cookies, array $headers): ResponseInterface
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest($method, self::ORIGIN . $path, ['REMOTE_ADDR' => '203.0.113.7'])
            ->withCookieParams($cookies);
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $this->app->handle($body === null ? $request : $request->withParsedBody($body));
    }
}
