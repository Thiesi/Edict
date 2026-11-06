<?php

declare(strict_types=1);

namespace Edict\Tests\Auth;

use Edict\Auth\Accounts;
use Edict\Auth\AdminAccount;
use Edict\Auth\OAuth\ProviderIdentity;
use Edict\Auth\SessionStore;
use Edict\Http\FlowCookie;
use Edict\Tests\DbTestCase;
use Psr\Http\Message\ResponseInterface;

final class OAuthLoginTest extends DbTestCase
{
    private const SESSION = 'edict_session';
    private const FLOW = 'edict_oauth';

    /**
     * @return array{cookie: string, params: array<string, string>}
     */
    private function startFlow(string $provider = 'google', string $query = ''): array
    {
        return self::flowFrom($this->get("/auth/$provider$query"));
    }

    /**
     * @return array{cookie: string, params: array<string, string>}
     */
    private static function flowFrom(ResponseInterface $response): array
    {
        self::assertSame(303, $response->getStatusCode());
        parse_str((string) parse_url($response->getHeaderLine('Location'), PHP_URL_QUERY), $params);

        /** @var array<string, string> $params */
        return ['cookie' => self::cookieFrom($response, self::FLOW), 'params' => $params];
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
     * @param array{cookie: string, params: array<string, string>} $flow
     * @param array<string, mixed> $claims overrides for the ID token
     * @param array<string, string> $cookies
     */
    private function finish(array $flow, array $claims = [], string $provider = 'google', string $subject = 'subject-1', array $cookies = []): ResponseInterface
    {
        $issuers = ['google' => 'https://accounts.google.com', 'twitch' => 'https://id.twitch.tv/oauth2'];
        $this->http->claims = $claims + [
            'iss' => $issuers[$provider],
            'aud' => "$provider-client",
            'exp' => $this->clock->now() + 3600,
            'nonce' => $flow['params']['nonce'],
            'sub' => $subject,
            'name' => 'Ada Lovelace',
            'preferred_username' => 'ada_twitch',
            'email' => 'ada@example.org',
        ];
        $query = http_build_query(['code' => 'the-code', 'state' => $flow['params']['state']]);

        return $this->get("/auth/$provider/callback?$query", $cookies + [self::FLOW => $flow['cookie']]);
    }

    /**
     * @return array<string, string> cookies of a freshly signed-in reader
     */
    private function signIn(string $provider = 'google', string $subject = 'subject-1'): array
    {
        $response = $this->finish($this->startFlow($provider), provider: $provider, subject: $subject);
        self::assertSame(303, $response->getStatusCode());

        return [self::SESSION => self::cookieFrom($response, self::SESSION)];
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

    private function countRows(string $sql): int
    {
        $stmt = $this->pdo->query($sql);
        self::assertNotFalse($stmt);

        return (int) $stmt->fetchColumn();
    }

    private function createAdmin(): void
    {
        $this->service(AdminAccount::class)->create('sovereign', 'correct horse battery');
    }

    public function testStartRedirectsWithStateNonceAndPkce(): void
    {
        $response = $this->get('/auth/google');

        self::assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth?', $response->getHeaderLine('Location'));
        $params = self::flowFrom($response)['params'];
        self::assertSame('code', $params['response_type']);
        self::assertSame('google-client', $params['client_id']);
        self::assertSame('http://localhost:8080/auth/google/callback', $params['redirect_uri']);
        self::assertSame('openid profile', $params['scope']);
        self::assertSame('S256', $params['code_challenge_method']);
        self::assertNotSame('', $params['state']);
        self::assertNotSame('', $params['nonce']);
        self::assertNotSame($params['state'], $params['nonce']);

        $cookie = $response->getHeaderLine('Set-Cookie');
        self::assertStringContainsString('HttpOnly', $cookie);
        self::assertStringContainsString('SameSite=Lax', $cookie);
    }

    public function testTwitchAsksForTheNameClaim(): void
    {
        $params = $this->startFlow('twitch')['params'];

        self::assertSame('openid', $params['scope']);
        self::assertSame('{"id_token":{"preferred_username":null}}', $params['claims']);
    }

    public function testStartingALoginWritesNothingToTheDatabase(): void
    {
        $this->startFlow();

        self::assertSame(0, $this->countRows('SELECT count(*) FROM login_attempt'));
        self::assertSame(0, $this->countRows('SELECT count(*) FROM session'));
    }

    public function testUnknownProviderIs404(): void
    {
        self::assertSame(404, $this->get('/auth/myspace')->getStatusCode());
        self::assertSame(404, $this->get('/auth/myspace/callback')->getStatusCode());
    }

    public function testFirstLoginCreatesAccountStoringOnlyIdAndName(): void
    {
        $flow = $this->startFlow();
        $response = $this->finish($flow);

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/account?notice=welcome', $response->getHeaderLine('Location'));

        $request = $this->http->requests[0];
        self::assertSame('https://oauth2.googleapis.com/token', $request['url']);
        self::assertSame('the-code', $request['fields']['code']);
        self::assertSame('google-secret', $request['fields']['client_secret']);
        self::assertSame('http://localhost:8080/auth/google/callback', $request['fields']['redirect_uri']);
        self::assertNotSame('', $request['fields']['code_verifier']);

        $row = $this->pdo->query('SELECT u.display_name, u.is_admin, i.provider, i.subject FROM app_user u JOIN user_identity i ON i.user_id = u.id');
        self::assertNotFalse($row);
        self::assertSame(
            [['display_name' => 'Ada Lovelace', 'is_admin' => false, 'provider' => 'google', 'subject' => 'subject-1']],
            $row->fetchAll(),
        );
    }

    public function testFlowCookieIsClearedAfterCallback(): void
    {
        $response = $this->finish($this->startFlow());

        $cleared = array_filter($response->getHeader('Set-Cookie'), static fn(string $h): bool => str_starts_with($h, self::FLOW . '=;'));
        self::assertCount(1, $cleared);
    }

    public function testSecondLoginFindsSameAccountAndGoesToNext(): void
    {
        $this->signIn();

        $response = $this->finish($this->startFlow(query: '?next=%2Fsome-article'), ['name' => 'Renamed At Google']);

        self::assertSame('/some-article', $response->getHeaderLine('Location'));
        self::assertSame(1, $this->countRows('SELECT count(*) FROM app_user'));
        self::assertSame(1, $this->countRows("SELECT count(*) FROM app_user WHERE display_name = 'Ada Lovelace'"));
    }

    public function testOffsiteNextIsIgnored(): void
    {
        $this->signIn();

        $response = $this->finish($this->startFlow(query: '?next=%2F%2Fevil.example'));

        self::assertSame('/', $response->getHeaderLine('Location'));
    }

    public function testSameNameAtOtherProviderIsADifferentAccount(): void
    {
        $this->signIn('google', 'shared-id');
        $this->signIn('twitch', 'shared-id');

        self::assertSame(2, $this->countRows('SELECT count(*) FROM app_user'));
    }

    public function testLoginReplacesExistingSession(): void
    {
        $old = $this->signIn();

        $response = $this->finish($this->startFlow(), cookies: $old);

        self::assertNotSame($old[self::SESSION], self::cookieFrom($response, self::SESSION));
        self::assertSame(303, $this->get('/account', $old)->getStatusCode());
        self::assertSame('/login', $this->get('/account', $old)->getHeaderLine('Location'));
    }

    public function testMissingNameFallsBack(): void
    {
        $this->finish($this->startFlow(), ['name' => "\u{200B} "]);

        self::assertSame(1, $this->countRows("SELECT count(*) FROM app_user WHERE display_name = 'Reader'"));
    }

    public function testLongProviderNameIsTruncated(): void
    {
        self::assertSame(303, $this->finish($this->startFlow(), ['name' => str_repeat('ü', 100)])->getStatusCode());
        self::assertSame(1, $this->countRows('SELECT count(*) FROM app_user WHERE char_length(display_name) = 64'));
    }

    public function testCallbackWithoutFlowCookieIsRejected(): void
    {
        $flow = $this->startFlow();
        $flow['cookie'] = '';

        $response = $this->finish($flow);

        self::assertSame('/login?notice=expired', $response->getHeaderLine('Location'));
        self::assertSame([], $this->http->requests);
        self::assertSame(0, $this->countRows('SELECT count(*) FROM app_user'));
    }

    public function testCallbackWithForeignStateIsRejected(): void
    {
        $attacker = $this->startFlow();
        $victim = $this->startFlow();
        $mixed = ['cookie' => $victim['cookie'], 'params' => $attacker['params']];

        $response = $this->finish($mixed);

        self::assertSame('/login?notice=expired', $response->getHeaderLine('Location'));
        self::assertSame([], $this->http->requests);
    }

    public function testTamperedFlowCookieIsRejected(): void
    {
        $flow = $this->startFlow();
        $flow['cookie'] = strrev($flow['cookie']);

        self::assertSame('/login?notice=expired', $this->finish($flow)->getHeaderLine('Location'));
    }

    public function testExpiredFlowIsRejected(): void
    {
        $flow = $this->startFlow();
        $this->clock->advance(FlowCookie::TTL + 1);

        self::assertSame('/login?notice=expired', $this->finish($flow)->getHeaderLine('Location'));
    }

    public function testFlowOfOtherProviderIsRejected(): void
    {
        $flow = $this->startFlow('twitch');

        self::assertSame('/login?notice=expired', $this->finish($flow, provider: 'google')->getHeaderLine('Location'));
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function badClaims(): iterable
    {
        yield 'wrong issuer' => [['iss' => 'https://evil.example']];
        yield 'wrong audience' => [['aud' => 'someone-else']];
        yield 'expired' => [['exp' => 1]];
        yield 'wrong nonce' => [['nonce' => 'replayed']];
        yield 'no subject' => [['sub' => '']];
    }

    /**
     * @param array<string, mixed> $claims
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('badClaims')]
    public function testBadIdTokenIsRejected(array $claims): void
    {
        $response = $this->finish($this->startFlow(), $claims);

        self::assertSame('/login?notice=provider-error', $response->getHeaderLine('Location'));
        self::assertSame(0, $this->countRows('SELECT count(*) FROM app_user'));
    }

    public function testTokenEndpointFailuresAreHandled(): void
    {
        foreach ([
            ['status' => 400, 'body' => '{"error":"invalid_grant"}'],
            ['status' => 200, 'body' => '{"access_token":"x"}'],
            ['status' => 200, 'body' => '{"id_token":"not-a-jwt"}'],
            ['status' => 200, 'body' => 'not json'],
        ] as $answer) {
            $this->http->rawResponse = $answer;
            self::assertSame('/login?notice=provider-error', $this->finish($this->startFlow())->getHeaderLine('Location'));
        }

        $this->http->rawResponse = null;
        $this->http->unreachable = true;
        $response = $this->finish($this->startFlow());
        self::assertSame('/login?notice=provider-error', $response->getHeaderLine('Location'));
        self::assertCount(5, $this->http->requests);
    }

    public function testDeclinedAtProviderCountsAsCancelled(): void
    {
        $flow = $this->startFlow();

        $response = $this->get(
            '/auth/google/callback?error=access_denied&state=' . urlencode($flow['params']['state']),
            [self::FLOW => $flow['cookie']],
        );

        self::assertSame('/login?notice=cancelled', $response->getHeaderLine('Location'));
        self::assertSame([], $this->http->requests);
        self::assertSame(0, $this->countRows('SELECT count(*) FROM login_attempt'));
    }

    public function testRepeatedProviderFailuresAreThrottledWithoutTouchingPasswordLogin(): void
    {
        $this->createAdmin();
        $this->http->unreachable = true;
        for ($i = 0; $i < 20; ++$i) {
            $this->finish($this->startFlow());
        }
        self::assertCount(20, $this->http->requests);

        $blocked = $this->finish($this->startFlow());
        self::assertSame(429, $blocked->getStatusCode());
        self::assertCount(20, $this->http->requests);

        $password = $this->post('/admin/login', ['username' => 'sovereign', 'password' => 'wrong wrong wrong', 'code' => '000000']);
        self::assertSame(401, $password->getStatusCode());
    }

    public function testCrossSiteCallbacksCannotLockAnyoneOut(): void
    {
        for ($i = 0; $i < 25; ++$i) {
            $this->get('/auth/google/callback');
        }

        self::assertSame(0, $this->countRows('SELECT count(*) FROM login_attempt'));
        self::assertSame(303, $this->finish($this->startFlow())->getStatusCode());
    }

    public function testStartWhileSignedInKeepsTheSession(): void
    {
        $cookies = $this->signIn();

        $response = $this->get('/auth/twitch?next=%2Fan-article', $cookies);

        self::assertSame('/an-article', $response->getHeaderLine('Location'));
        self::assertSame('', $response->getHeaderLine('Set-Cookie'));
        self::assertSame(200, $this->get('/account', $cookies)->getStatusCode());
    }

    public function testCookiesAreReadFromTheRawHeader(): void
    {
        $token = $this->signIn()[self::SESSION];

        self::assertSame(200, $this->get('/account', [], ['Cookie' => self::SESSION . '=' . $token])->getStatusCode());
        self::assertSame(303, $this->get('/account', [], ['Cookie' => 'edict%5Fsession=' . $token])->getStatusCode());

        $flow = $this->startFlow();
        $response = $this->get(
            '/auth/google/callback?code=c&state=' . urlencode($flow['params']['state']),
            [],
            ['Cookie' => 'edict%5Foauth=' . $flow['cookie']],
        );
        self::assertSame('/login?notice=expired', $response->getHeaderLine('Location'));
    }

    public function testNoticeOnlyShowsKnownTexts(): void
    {
        $body = (string) $this->get('/login?notice=<script>alert(1)</script>')->getBody();
        self::assertStringNotContainsString('alert(1)', $body);

        self::assertStringContainsString('has expired', (string) $this->get('/login?notice=expired')->getBody());
    }

    public function testLoginPageListsProvidersAndCarriesNext(): void
    {
        $body = (string) $this->get('/login?next=%2Fan-article')->getBody();

        self::assertStringContainsString('/auth/google?next=%2Fan-article', $body);
        self::assertStringContainsString('/auth/twitch?next=%2Fan-article', $body);
    }

    public function testAccountPageNeedsLogin(): void
    {
        $response = $this->get('/account');

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/login', $response->getHeaderLine('Location'));
    }

    public function testAccountPageShowsIdentityAndIsNotCached(): void
    {
        $response = $this->get('/account', $this->signIn());

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        $body = (string) $response->getBody();
        self::assertStringContainsString('subject-1', $body);
        self::assertStringContainsString('Add Twitch login', $body);
        self::assertStringNotContainsString('Add Google login', $body);
    }

    public function testReaderIsForbiddenFromAdmin(): void
    {
        self::assertSame(403, $this->get('/admin', $this->signIn())->getStatusCode());
    }

    public function testRenameNeedsCsrfAndValidName(): void
    {
        $cookies = $this->signIn();
        $csrf = $this->csrf($cookies);

        self::assertSame(403, $this->post('/account/name', ['display_name' => 'Hacked'], $cookies)->getStatusCode());

        $ok = $this->post('/account/name', ['_csrf' => $csrf, 'display_name' => '  New   Name '], $cookies);
        self::assertSame('/account?notice=renamed', $ok->getHeaderLine('Location'));
        self::assertSame(1, $this->countRows("SELECT count(*) FROM app_user WHERE display_name = 'New Name'"));

        foreach (['', '   ', str_repeat('x', 65), Accounts::DELETED_NAME] as $bad) {
            $response = $this->post('/account/name', ['_csrf' => $csrf, 'display_name' => $bad], $cookies);
            self::assertSame('/account?notice=invalid-name', $response->getHeaderLine('Location'));
        }
        self::assertSame(1, $this->countRows("SELECT count(*) FROM app_user WHERE display_name = 'New Name'"));
    }

    public function testNameIsEscapedOnTheAccountPage(): void
    {
        $cookies = $this->signIn();
        $this->post('/account/name', ['_csrf' => $this->csrf($cookies), 'display_name' => '"><script>x</script>'], $cookies);

        self::assertStringNotContainsString('<script>x', (string) $this->get('/account', $cookies)->getBody());
    }

    public function testLinkAddsSecondProviderToSameAccount(): void
    {
        $cookies = $this->signIn();

        $flow = self::flowFrom($this->post('/account/link/twitch', ['_csrf' => $this->csrf($cookies)], $cookies));
        $response = $this->finish($flow, provider: 'twitch', subject: 'twitch-id', cookies: $cookies);

        self::assertSame('/account?notice=linked', $response->getHeaderLine('Location'));
        self::assertSame(1, $this->countRows('SELECT count(*) FROM app_user'));
        self::assertSame(2, $this->countRows('SELECT count(*) FROM user_identity'));

        // Signing in with the linked provider reaches the same account.
        self::assertSame(303, $this->finish($this->startFlow('twitch'), provider: 'twitch', subject: 'twitch-id')->getStatusCode());
        self::assertSame(1, $this->countRows('SELECT count(*) FROM app_user'));
    }

    public function testLinkNeedsCsrf(): void
    {
        self::assertSame(403, $this->post('/account/link/twitch', [], $this->signIn())->getStatusCode());
    }

    public function testLinkNeverTakesAnIdentityFromAnotherAccount(): void
    {
        $this->signIn('twitch', 'someone-elses');
        $cookies = $this->signIn('google', 'mine');

        $flow = self::flowFrom($this->post('/account/link/twitch', ['_csrf' => $this->csrf($cookies)], $cookies));
        $response = $this->finish($flow, provider: 'twitch', subject: 'someone-elses', cookies: $cookies);

        self::assertSame('/account?notice=taken', $response->getHeaderLine('Location'));
        self::assertSame(2, $this->countRows('SELECT count(*) FROM app_user'));
        self::assertSame(1, $this->countRows("SELECT count(*) FROM user_identity WHERE provider = 'twitch'"));
    }

    public function testSecondLoginOfSameProviderCannotBeLinked(): void
    {
        $cookies = $this->signIn('google', 'first');

        $flow = self::flowFrom($this->post('/account/link/google', ['_csrf' => $this->csrf($cookies)], $cookies));
        $response = $this->finish($flow, subject: 'second', cookies: $cookies);

        self::assertSame('/account?notice=provider-in-use', $response->getHeaderLine('Location'));
    }

    public function testLinkFlowIsBoundToTheAccountThatStartedIt(): void
    {
        $alice = $this->signIn('google', 'alice');
        $flow = self::flowFrom($this->post('/account/link/twitch', ['_csrf' => $this->csrf($alice)], $alice));

        // Signed out meanwhile.
        $signedOut = $this->finish($flow, provider: 'twitch', subject: 'new');
        self::assertSame('/login?notice=expired', $signedOut->getHeaderLine('Location'));

        // A different account is signed in meanwhile.
        $bob = $this->signIn('google', 'bob');
        $other = $this->finish($flow, provider: 'twitch', subject: 'new', cookies: $bob);
        self::assertSame('/account?notice=expired', $other->getHeaderLine('Location'));

        self::assertSame(0, $this->countRows("SELECT count(*) FROM user_identity WHERE provider = 'twitch'"));
    }

    public function testDeleteAccountErasesPersonalDataAndEndsSessions(): void
    {
        $cookies = $this->signIn();

        $unconfirmed = $this->post('/account/delete', ['_csrf' => $this->csrf($cookies)], $cookies);
        self::assertSame('/account?notice=confirm-delete', $unconfirmed->getHeaderLine('Location'));
        self::assertSame(1, $this->countRows('SELECT count(*) FROM user_identity'));

        $response = $this->post('/account/delete', ['_csrf' => $this->csrf($cookies), 'confirm' => 'yes'], $cookies);
        self::assertStringContainsString('Max-Age=0', $response->getHeaderLine('Set-Cookie'));

        self::assertSame(0, $this->countRows('SELECT count(*) FROM user_identity'));
        self::assertSame(0, $this->countRows('SELECT count(*) FROM session'));
        self::assertSame(1, $this->countRows("SELECT count(*) FROM app_user WHERE display_name = '[deleted user]' AND deleted_at IS NOT NULL"));
        self::assertSame('/login', $this->get('/account', $cookies)->getHeaderLine('Location'));
    }

    public function testLoginAfterDeletionStartsANewAccount(): void
    {
        $cookies = $this->signIn();
        $this->post('/account/delete', ['_csrf' => $this->csrf($cookies), 'confirm' => 'yes'], $cookies);

        $response = $this->finish($this->startFlow());

        self::assertSame('/account?notice=welcome', $response->getHeaderLine('Location'));
        self::assertSame(2, $this->countRows('SELECT count(*) FROM app_user'));
    }

    public function testLogoutEverywhereEndsAllSessions(): void
    {
        $first = $this->signIn();
        $second = $this->signIn();

        $this->post('/account/logout-everywhere', ['_csrf' => $this->csrf($first)], $first);

        self::assertSame(0, $this->countRows('SELECT count(*) FROM session'));
        self::assertSame(303, $this->get('/account', $second)->getStatusCode());
    }

    public function testAllowListedAccountSignsInAsAdminWithShortSession(): void
    {
        $this->createAdmin();
        self::assertSame('added', $this->service(Accounts::class)->allowForAdmin('google', 'owner'));

        $response = $this->finish($this->startFlow(query: '?next=%2Fadmin'), subject: 'owner');

        self::assertSame('/admin', $response->getHeaderLine('Location'));
        $cookies = [self::SESSION => self::cookieFrom($response, self::SESSION)];
        self::assertSame(200, $this->get('/admin', $cookies)->getStatusCode());
        self::assertSame(1, $this->countRows('SELECT count(*) FROM app_user'));

        $this->clock->advance(SessionStore::ADMIN_TTL + 1);
        self::assertSame(303, $this->get('/admin', $cookies)->getStatusCode());
    }

    public function testAccountNotOnTheAllowListNeverBecomesAdmin(): void
    {
        $this->createAdmin();
        $this->service(Accounts::class)->allowForAdmin('google', 'owner');

        $cookies = $this->signIn('google', 'not-the-owner');

        self::assertSame(403, $this->get('/admin', $cookies)->getStatusCode());
    }

    public function testAllowingMovesIdentityFromReaderAndErasesEmptyReader(): void
    {
        $this->createAdmin();
        $reader = $this->signIn('google', 'owner');

        self::assertSame('moved', $this->service(Accounts::class)->allowForAdmin('google', 'owner'));

        self::assertSame('/login', $this->get('/account', $reader)->getHeaderLine('Location'));
        self::assertSame(1, $this->countRows("SELECT count(*) FROM app_user WHERE deleted_at IS NOT NULL AND display_name = '[deleted user]'"));
        self::assertSame('unchanged', $this->service(Accounts::class)->allowForAdmin('google', 'owner'));
        self::assertSame([['provider' => 'google', 'subject' => 'owner']], $this->service(Accounts::class)->adminIdentities());
    }

    public function testAllowingKeepsReaderWhoHasAnotherLogin(): void
    {
        $this->createAdmin();
        $accounts = $this->service(Accounts::class);
        $reader = $accounts->findOrCreate(new ProviderIdentity('google', 'owner', 'Owner'));
        $accounts->link($reader['id'], new ProviderIdentity('twitch', 'other', 'Owner'));

        $accounts->allowForAdmin('google', 'owner');

        self::assertSame([['provider' => 'twitch', 'subject' => 'other']], $accounts->identities($reader['id']));
    }

    public function testSecondAccountOfSameProviderIsRefusedForAdmin(): void
    {
        $this->createAdmin();
        $accounts = $this->service(Accounts::class);
        $accounts->allowForAdmin('google', 'first');

        $this->expectException(\DomainException::class);
        $accounts->allowForAdmin('google', 'second');
    }

    public function testAllowingNeedsAnAdmin(): void
    {
        $this->expectException(\DomainException::class);
        $this->service(Accounts::class)->allowForAdmin('google', 'owner');
    }

    public function testDisallowingEndsAdminSessionsAndAccess(): void
    {
        $this->createAdmin();
        $accounts = $this->service(Accounts::class);
        $accounts->allowForAdmin('google', 'owner');
        $response = $this->finish($this->startFlow(), subject: 'owner');
        $cookies = [self::SESSION => self::cookieFrom($response, self::SESSION)];

        self::assertFalse($accounts->disallowForAdmin('google', 'unknown'));
        self::assertSame(200, $this->get('/admin', $cookies)->getStatusCode());

        self::assertTrue($accounts->disallowForAdmin('google', 'owner'));
        self::assertSame(303, $this->get('/admin', $cookies)->getStatusCode());

        // The same provider account is now an ordinary reader.
        $reader = $this->signIn('google', 'owner');
        self::assertSame(403, $this->get('/admin', $reader)->getStatusCode());
    }

    public function testAdminCannotLinkOrDeleteFromTheWeb(): void
    {
        $this->createAdmin();
        $accounts = $this->service(Accounts::class);
        $accounts->allowForAdmin('google', 'owner');
        $response = $this->finish($this->startFlow(), subject: 'owner');
        $cookies = [self::SESSION => self::cookieFrom($response, self::SESSION)];
        $csrf = $this->csrf($cookies);

        $link = $this->post('/account/link/twitch', ['_csrf' => $csrf], $cookies);
        self::assertSame('/account?notice=admin-cli', $link->getHeaderLine('Location'));

        $this->post('/account/delete', ['_csrf' => $csrf, 'confirm' => 'yes'], $cookies);
        self::assertSame(200, $this->get('/admin', $cookies)->getStatusCode());

        $this->expectException(\DomainException::class);
        $accounts->delete(1);
    }

    public function testAdminLoginPageOffersProviders(): void
    {
        self::assertStringContainsString('/auth/twitch?next=%2Fadmin', (string) $this->get('/admin/login')->getBody());
    }

    public function testHomeLinksToSignInOrAccount(): void
    {
        self::assertStringContainsString('href="/login"', (string) $this->get('/')->getBody());
        self::assertStringContainsString('href="/account"', (string) $this->get('/', $this->signIn())->getBody());
    }
}
