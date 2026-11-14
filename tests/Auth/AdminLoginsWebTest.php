<?php

declare(strict_types=1);

namespace Edict\Tests\Auth;

use Edict\Auth\Accounts;
use Edict\Auth\AdminAccount;
use Edict\Auth\SessionStore;
use Edict\Auth\Totp;
use Edict\Tests\DbTestCase;
use Psr\Http\Message\ResponseInterface;

final class AdminLoginsWebTest extends DbTestCase
{
    private const PASSWORD = 'correct horse battery';
    private const SESSION = 'edict_session';
    private const FLOW = 'edict_oauth';

    private string $secret;

    protected function setUp(): void
    {
        parent::setUp();
        $this->secret = $this->service(AdminAccount::class)->create('sovereign', self::PASSWORD);
    }

    /**
     * A fresh code; each one works only once, so the clock moves on afterwards.
     */
    private function code(): string
    {
        $code = Totp::code($this->secret, intdiv($this->clock->now(), 30));
        $this->clock->advance(31);

        return $code;
    }

    /**
     * @return array<string, string>
     */
    private function adminSession(): array
    {
        $response = $this->post('/admin/login', ['username' => 'sovereign', 'password' => self::PASSWORD, 'code' => $this->code()]);
        self::assertSame(303, $response->getStatusCode());

        return [self::SESSION => self::cookieFrom($response, self::SESSION)];
    }

    private static function cookieFrom(ResponseInterface $response, string $name): string
    {
        foreach ($response->getHeader('Set-Cookie') as $header) {
            if (preg_match('/^' . $name . '=([^;]+)/', $header, $match) === 1) {
                return $match[1];
            }
        }
        self::fail("Cookie $name was not set");
    }

    /**
     * @param array<string, string> $cookies
     */
    private function csrf(array $cookies): string
    {
        $session = $this->service(SessionStore::class)->load($cookies[self::SESSION]);
        self::assertNotNull($session);

        return $session->csrfToken;
    }

    /**
     * @param array<string, string> $cookies
     */
    private function change(array $cookies, string $action, ?string $code = null): ResponseInterface
    {
        return $this->post('/account/admin-logins', [
            '_csrf' => $this->csrf($cookies),
            'password' => self::PASSWORD,
            'code' => $code ?? $this->code(),
            'action' => $action,
        ], $cookies);
    }

    /**
     * @param array<string, string> $cookies
     */
    private function finish(ResponseInterface $start, string $provider, string $subject, array $cookies): ResponseInterface
    {
        self::assertSame(303, $start->getStatusCode());
        parse_str((string) parse_url($start->getHeaderLine('Location'), PHP_URL_QUERY), $params);
        self::assertIsString($params['nonce'] ?? null);
        self::assertIsString($params['state'] ?? null);
        $issuers = ['google' => 'https://accounts.google.com', 'twitch' => 'https://id.twitch.tv/oauth2'];
        $this->http->claims = [
            'iss' => $issuers[$provider],
            'aud' => "$provider-client",
            'exp' => $this->clock->now() + 3600,
            'nonce' => $params['nonce'],
            'sub' => $subject,
            'name' => 'Someone',
            'preferred_username' => 'someone',
        ];
        $query = http_build_query(['code' => 'the-code', 'state' => $params['state']]);

        return $this->get("/auth/$provider/callback?$query", $cookies + [self::FLOW => self::cookieFrom($start, self::FLOW)]);
    }

    /**
     * @return list<array{provider: string, subject: string}>
     */
    private function adminIdentities(): array
    {
        return $this->service(Accounts::class)->adminIdentities();
    }

    public function testTheAccountPageOffersTheLoginsWithAConfirmation(): void
    {
        $this->service(Accounts::class)->allowForAdmin('twitch', 'owner-twitch');

        $page = (string) $this->get('/account', $this->adminSession())->getBody();

        self::assertStringContainsString('action="/account/admin-logins"', $page);
        self::assertStringContainsString('value="add:google">Add Google login', $page);
        self::assertStringContainsString('value="remove:twitch">Remove Twitch login', $page);
        self::assertStringContainsString('autocomplete="one-time-code"', $page);
        self::assertStringNotContainsString('Combine with', $page);
    }

    public function testAddingALoginNeedsThePasswordAndCode(): void
    {
        $cookies = $this->adminSession();

        $wrong = $this->change($cookies, 'add:google', '000000');

        self::assertSame('/account?notice=admin-not-confirmed', $wrong->getHeaderLine('Location'));
        self::assertSame([], array_filter($wrong->getHeader('Set-Cookie'), static fn(string $h): bool => str_starts_with($h, self::FLOW . '=')));
        self::assertSame(403, $this->post('/account/admin-logins', ['action' => 'add:google'], $cookies)->getStatusCode());

        // A code can't be used twice.
        $code = $this->code();
        self::assertSame(303, $this->change($cookies, 'add:google', $code)->getStatusCode());
        self::assertSame('/account?notice=admin-not-confirmed', $this->change($cookies, 'add:google', $code)->getHeaderLine('Location'));
    }

    public function testTheAdminAddsAGoogleLoginAndCanSignInWithIt(): void
    {
        $cookies = $this->adminSession();

        $start = $this->change($cookies, 'add:google');
        self::assertStringStartsWith('https://accounts.google.com/', $start->getHeaderLine('Location'));
        $done = $this->finish($start, 'google', 'owner-google', $cookies);

        self::assertSame('/account?notice=linked', $done->getHeaderLine('Location'));
        self::assertSame([['provider' => 'google', 'subject' => 'owner-google']], $this->adminIdentities());
        self::assertSame(200, $this->get('/admin', $cookies)->getStatusCode(), 'adding keeps the session');

        $signIn = $this->get('/auth/google');
        $admin = $this->finish($signIn, 'google', 'owner-google', []);
        self::assertSame(200, $this->get('/admin', [self::SESSION => self::cookieFrom($admin, self::SESSION)])->getStatusCode());
    }

    public function testAddingTakesTheLoginFromAReaderAccount(): void
    {
        $reader = $this->finish($this->get('/auth/google'), 'google', 'owner-google', []);
        self::assertSame('/account?notice=welcome', $reader->getHeaderLine('Location'));
        $cookies = $this->adminSession();

        $done = $this->finish($this->change($cookies, 'add:google'), 'google', 'owner-google', $cookies);

        self::assertSame('/account?notice=admin-moved', $done->getHeaderLine('Location'));
        $deleted = $this->pdo->query('SELECT count(*) FROM app_user WHERE deleted_at IS NOT NULL');
        self::assertNotFalse($deleted);
        self::assertSame(1, $deleted->fetchColumn());
    }

    public function testASecondLoginOfTheSameProviderIsRefused(): void
    {
        $this->service(Accounts::class)->allowForAdmin('google', 'first');
        $cookies = $this->adminSession();

        $done = $this->finish($this->change($cookies, 'add:google'), 'google', 'second', $cookies);

        self::assertSame('/account?notice=provider-in-use', $done->getHeaderLine('Location'));
        self::assertSame([['provider' => 'google', 'subject' => 'first']], $this->adminIdentities());
    }

    public function testRemovingALoginEndsEveryOtherAdminSession(): void
    {
        $this->service(Accounts::class)->allowForAdmin('google', 'owner-google');
        $elsewhere = $this->adminSession();
        $here = $this->adminSession();

        $removed = $this->change($here, 'remove:google');

        self::assertSame('/account?notice=admin-unlinked', $removed->getHeaderLine('Location'));
        self::assertSame([], $this->adminIdentities());
        self::assertSame(200, $this->get('/admin', $here)->getStatusCode());
        self::assertSame(303, $this->get('/admin', $elsewhere)->getStatusCode());
        self::assertSame('/account?notice=not-linked', $this->change($here, 'remove:google')->getHeaderLine('Location'));
    }

    public function testReadersAndUnknownActionsChangeNothing(): void
    {
        $cookies = $this->adminSession();
        self::assertSame('/account', $this->change($cookies, 'add:myspace')->getHeaderLine('Location'));
        self::assertSame('/account', $this->change($cookies, 'promote:google')->getHeaderLine('Location'));

        $reader = $this->finish($this->get('/auth/twitch'), 'twitch', 'reader', []);
        $readerCookies = [self::SESSION => self::cookieFrom($reader, self::SESSION)];
        self::assertSame('/account', $this->change($readerCookies, 'add:google')->getHeaderLine('Location'));
        self::assertSame([], $this->adminIdentities());
    }

    public function testAnAdminFlowFinishedInAnotherSessionIsRefused(): void
    {
        $cookies = $this->adminSession();
        $start = $this->change($cookies, 'add:google');
        $reader = $this->finish($this->get('/auth/twitch'), 'twitch', 'reader', []);

        $done = $this->finish($start, 'google', 'attacker', [self::SESSION => self::cookieFrom($reader, self::SESSION)]);

        self::assertSame('/account?notice=expired', $done->getHeaderLine('Location'));
        self::assertSame([], $this->adminIdentities());
    }
}
