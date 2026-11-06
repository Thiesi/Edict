<?php

declare(strict_types=1);

namespace Edict\Tests\Auth;

use Edict\Auth\AdminAccount;
use Edict\Auth\LoginThrottle;
use Edict\Auth\SessionStore;
use Edict\Auth\Totp;
use Edict\Tests\DbTestCase;
use Psr\Http\Message\ResponseInterface;

final class AdminLoginTest extends DbTestCase
{
    private const PASSWORD = 'correct horse battery';
    private const COOKIE = 'edict_session';

    private string $secret;

    protected function setUp(): void
    {
        parent::setUp();
        $this->secret = $this->service(AdminAccount::class)->create('Sovereign', self::PASSWORD);
    }

    private function code(int $stepOffset = 0): string
    {
        return Totp::code($this->secret, intdiv($this->clock->now(), 30) + $stepOffset);
    }

    private function attempt(?string $password = null, ?string $code = null, string $username = 'sovereign'): ResponseInterface
    {
        return $this->post('/admin/login', [
            'username' => $username,
            'password' => $password ?? self::PASSWORD,
            'code' => $code ?? $this->code(),
        ]);
    }

    /**
     * @return array<string, string> cookie params of a logged-in admin
     */
    private function logIn(): array
    {
        $response = $this->attempt();
        self::assertSame(303, $response->getStatusCode());

        return [self::COOKIE => self::tokenFrom($response)];
    }

    private static function tokenFrom(ResponseInterface $response): string
    {
        if (preg_match('/^edict_session=([^;]+)/', $response->getHeaderLine('Set-Cookie'), $m) !== 1) {
            self::fail('No session cookie was set');
        }

        return $m[1];
    }

    /**
     * @param array<string, string> $cookies
     */
    private function csrf(array $cookies): string
    {
        $session = $this->service(SessionStore::class)->load($cookies[self::COOKIE]);
        self::assertNotNull($session);

        return $session->csrfToken;
    }

    public function testValidLoginSetsHardenedCookieAndOpensDashboard(): void
    {
        $response = $this->attempt();

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/admin', $response->getHeaderLine('Location'));
        $cookie = $response->getHeaderLine('Set-Cookie');
        self::assertStringContainsString('HttpOnly', $cookie);
        self::assertStringContainsString('SameSite=Lax', $cookie);

        $dashboard = $this->get('/admin', [self::COOKIE => self::tokenFrom($response)]);
        self::assertSame(200, $dashboard->getStatusCode());
        self::assertStringContainsString('Signed in as sovereign', (string) $dashboard->getBody());
        self::assertSame('no-store', $dashboard->getHeaderLine('Cache-Control'));
    }

    public function testUsernameIsCaseInsensitive(): void
    {
        self::assertSame(303, $this->attempt(username: '  SOVEREIGN ')->getStatusCode());
    }

    public function testWrongPasswordWrongCodeAndUnknownUserLookIdentical(): void
    {
        $bodies = [];
        foreach ([
            $this->attempt(password: 'wrong wrong wrong'),
            $this->attempt(code: '000000'),
            $this->attempt(username: 'nobody'),
        ] as $response) {
            self::assertSame(401, $response->getStatusCode());
            self::assertSame('', $response->getHeaderLine('Set-Cookie'));
            $bodies[] = (string) $response->getBody();
        }

        self::assertCount(1, array_unique($bodies));
    }

    public function testCodeCannotBeReused(): void
    {
        $code = $this->code();

        self::assertSame(303, $this->attempt(code: $code)->getStatusCode());
        self::assertSame(401, $this->attempt(code: $code)->getStatusCode());
    }

    public function testOlderCodeCannotFollowNewerOne(): void
    {
        self::assertSame(303, $this->attempt(code: $this->code(1))->getStatusCode());
        self::assertSame(401, $this->attempt(code: $this->code())->getStatusCode());
    }

    public function testThrottleBlocksEvenCorrectCredentialsThenExpires(): void
    {
        for ($i = 0; $i < 5; ++$i) {
            self::assertSame(401, $this->attempt(password: 'wrong wrong wrong')->getStatusCode());
        }

        $blocked = $this->attempt();
        self::assertSame(429, $blocked->getStatusCode());
        self::assertSame((string) LoginThrottle::WINDOW, $blocked->getHeaderLine('Retry-After'));

        $this->clock->advance(LoginThrottle::WINDOW + 1);
        self::assertSame(303, $this->attempt()->getStatusCode());
    }

    public function testThrottleTableStoresNoRawValues(): void
    {
        $this->attempt(password: 'wrong wrong wrong');

        $stmt = $this->pdo->query('SELECT bucket FROM login_attempt');
        self::assertNotFalse($stmt);
        $buckets = $stmt->fetchAll(\PDO::FETCH_COLUMN);
        self::assertCount(2, $buckets);
        foreach ($buckets as $bucket) {
            self::assertIsString($bucket);
            self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $bucket);
        }
    }

    public function testAdminAreaRedirectsVisitorsAndGarbageCookies(): void
    {
        foreach ([[], [self::COOKIE => 'garbage']] as $cookies) {
            $response = $this->get('/admin', $cookies);

            self::assertSame(303, $response->getStatusCode());
            self::assertSame('/admin/login', $response->getHeaderLine('Location'));
        }
    }

    public function testNonAdminGets403(): void
    {
        $insert = $this->pdo->query("INSERT INTO app_user (display_name) VALUES ('reader') RETURNING id");
        self::assertNotFalse($insert);
        $id = $insert->fetchColumn();
        self::assertIsNumeric($id);

        $token = $this->service(SessionStore::class)->create((int) $id, 3600);

        self::assertSame(403, $this->get('/admin', [self::COOKIE => $token])->getStatusCode());
    }

    public function testSessionExpires(): void
    {
        $cookies = $this->logIn();
        self::assertSame(200, $this->get('/admin', $cookies)->getStatusCode());

        $this->clock->advance(SessionStore::ADMIN_TTL + 1);

        self::assertSame(303, $this->get('/admin', $cookies)->getStatusCode());
    }

    public function testExpiredSessionsArePurgedOnCreate(): void
    {
        $this->logIn();
        $this->clock->advance(SessionStore::ADMIN_TTL + 31);
        $this->logIn();

        $count = $this->pdo->query('SELECT count(*) FROM session');
        self::assertNotFalse($count);
        self::assertSame(1, (int) $count->fetchColumn());
    }

    public function testStoredTokenIsHashed(): void
    {
        $cookies = $this->logIn();

        $stored = $this->pdo->query('SELECT token_hash FROM session');
        self::assertNotFalse($stored);
        self::assertNotSame($cookies[self::COOKIE], $stored->fetchColumn());
    }

    public function testLogoutRequiresCsrfTokenThenEndsSession(): void
    {
        $cookies = $this->logIn();

        self::assertSame(403, $this->post('/admin/logout', [], $cookies)->getStatusCode());
        self::assertSame(200, $this->get('/admin', $cookies)->getStatusCode());

        $response = $this->post('/admin/logout', ['_csrf' => $this->csrf($cookies)], $cookies);
        self::assertSame(303, $response->getStatusCode());
        self::assertStringContainsString('Max-Age=0', $response->getHeaderLine('Set-Cookie'));
        self::assertSame(303, $this->get('/admin', $cookies)->getStatusCode());
    }

    public function testCsrfHeaderIsAcceptedAsAlternativeToField(): void
    {
        $cookies = $this->logIn();

        $response = $this->post('/admin/logout', [], $cookies, ['X-CSRF-Token' => $this->csrf($cookies)]);

        self::assertSame(303, $response->getStatusCode());
    }

    public function testLogoutEverywhereEndsAllSessions(): void
    {
        $first = $this->logIn();
        $this->clock->advance(31);
        $second = $this->logIn();

        $this->post('/admin/logout-everywhere', ['_csrf' => $this->csrf($first)], $first);

        self::assertSame(303, $this->get('/admin', $first)->getStatusCode());
        self::assertSame(303, $this->get('/admin', $second)->getStatusCode());
    }

    public function testCrossOriginPostIsRejectedOnLoginWithoutSession(): void
    {
        $response = $this->post(
            '/admin/login',
            ['username' => 'sovereign', 'password' => self::PASSWORD, 'code' => $this->code()],
            [],
            ['Origin' => 'https://evil.example'],
        );

        self::assertSame(403, $response->getStatusCode());
    }

    public function testOriginComparisonIgnoresCase(): void
    {
        $response = $this->post(
            '/admin/login',
            ['username' => 'sovereign', 'password' => self::PASSWORD, 'code' => $this->code()],
            [],
            ['Origin' => 'http://LOCALHOST:8080'],
        );

        self::assertSame(303, $response->getStatusCode());
    }

    public function testSecondAdminIsRefused(): void
    {
        $this->expectException(\DomainException::class);
        $this->service(AdminAccount::class)->create('usurper', self::PASSWORD);
    }

    public function testPasswordPolicy(): void
    {
        foreach (['too short', str_repeat('x', 73)] as $bad) {
            try {
                $this->service(AdminAccount::class)->reset($bad);
                self::fail('Accepted a password violating the policy');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testResetReplacesCredentialsAndEndsSessions(): void
    {
        $cookies = $this->logIn();

        $result = $this->service(AdminAccount::class)->reset('a brand new passphrase');

        self::assertSame('sovereign', $result['username']);
        self::assertSame(303, $this->get('/admin', $cookies)->getStatusCode());

        $this->clock->advance(61);
        self::assertSame(401, $this->attempt()->getStatusCode());
        $code = Totp::code($result['secret'], intdiv($this->clock->now(), 30));
        self::assertSame(303, $this->attempt('a brand new passphrase', $code)->getStatusCode());
    }

    public function testTotpSecretIsEncryptedAtRest(): void
    {
        $stored = $this->pdo->query('SELECT totp_secret FROM admin_credential');
        self::assertNotFalse($stored);
        $value = $stored->fetchColumn();
        self::assertIsString($value);

        self::assertStringNotContainsString($this->secret, (string) base64_decode($value, true));
    }

    public function testMigrationsAreIdempotent(): void
    {
        $migrator = new \Edict\Database\Migrator($this->pdo, dirname(__DIR__, 2) . '/migrations');

        self::assertSame([], $migrator->migrate());
    }

    public function testRefusesDatabaseNotNamedTest(): void
    {
        $this->expectException(\RuntimeException::class);
        DbTestCase::connectToTestDatabase('pgsql://u:p@127.0.0.1:5432/edict_dev');
    }
}
