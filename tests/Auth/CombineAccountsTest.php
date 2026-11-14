<?php

declare(strict_types=1);

namespace Edict\Tests\Auth;

use Edict\Auth\Accounts;
use Edict\Auth\AdminAccount;
use Edict\Auth\OAuth\FlowState;
use Edict\Auth\OAuth\ProviderIdentity;
use Edict\Auth\SessionStore;
use Edict\Content\Body;
use Edict\Tests\DbTestCase;
use Psr\Http\Message\ResponseInterface;

final class CombineAccountsTest extends DbTestCase
{
    private const SESSION = 'edict_session';
    private const FLOW = 'edict_oauth';
    private const LAST = 'edict_last_provider';

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
            if (preg_match('/^' . $name . '=([^;]*)/', $header, $match) === 1) {
                return $match[1];
            }
        }
        self::fail("Cookie $name was not set");
    }

    /**
     * @param array{cookie: string, params: array<string, string>} $flow
     * @param array<string, string> $cookies
     */
    private function finish(array $flow, string $provider, string $subject, array $cookies = []): ResponseInterface
    {
        $issuers = ['google' => 'https://accounts.google.com', 'twitch' => 'https://id.twitch.tv/oauth2'];
        $this->http->claims = [
            'iss' => $issuers[$provider],
            'aud' => "$provider-client",
            'exp' => $this->clock->now() + 3600,
            'nonce' => $flow['params']['nonce'],
            'sub' => $subject,
            'name' => "Name of $subject",
            'preferred_username' => "Name of $subject",
        ];
        $query = http_build_query(['code' => 'the-code', 'state' => $flow['params']['state']]);

        return $this->get("/auth/$provider/callback?$query", $cookies + [self::FLOW => $flow['cookie']]);
    }

    /**
     * @param array<string, string> $cookies
     */
    private function signInResponse(string $provider, string $subject, array $cookies = []): ResponseInterface
    {
        return $this->finish(self::flowFrom($this->get("/auth/$provider")), $provider, $subject, $cookies);
    }

    /**
     * @return array<string, string>
     */
    private function signIn(string $provider, string $subject): array
    {
        return [self::SESSION => self::cookieFrom($this->signInResponse($provider, $subject), self::SESSION)];
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
    private function combine(array $cookies, string $provider, string $subject): ResponseInterface
    {
        $flow = self::flowFrom($this->post("/account/combine/$provider", ['_csrf' => $this->csrf($cookies)], $cookies));

        return $this->finish($flow, $provider, $subject, $cookies);
    }

    private function userOf(string $provider, string $subject): int
    {
        $stmt = $this->pdo->prepare('SELECT user_id FROM user_identity WHERE provider = ? AND subject = ?');
        $stmt->execute([$provider, $subject]);

        return (int) $stmt->fetchColumn();
    }

    private function scalar(string $sql, mixed ...$params): mixed
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchColumn();
    }

    private function number(string $sql, mixed ...$params): int
    {
        $value = $this->scalar($sql, ...$params);
        self::assertIsNumeric($value);

        return (int) $value;
    }

    private function article(string $slug): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO article (kind, slug, lang, title, body_json, status, published_at, created_at, updated_at)
             VALUES ('article', ?, 'en', 'An article', ?, 'published', to_timestamp(1), now(), now()) RETURNING id",
        );
        $stmt->execute([$slug, Body::EMPTY]);

        return (int) $stmt->fetchColumn();
    }

    private function like(int $articleId, int $userId): void
    {
        $this->pdo->prepare('INSERT INTO vote (article_id, user_id, created_at) VALUES (?, ?, now())')->execute([$articleId, $userId]);
    }

    private function comment(int $articleId, int $userId, string $body): void
    {
        $this->pdo->prepare("INSERT INTO comment (article_id, user_id, body, state, created_at) VALUES (?, ?, ?, 'visible', now())")
            ->execute([$articleId, $userId, $body]);
    }

    public function testANewAccountMadeWithTheOtherProviderGetsTheHint(): void
    {
        $first = $this->signInResponse('google', 'g-1');
        $last = self::cookieFrom($first, self::LAST);
        self::assertSame('google', $last);
        self::assertStringContainsString('Max-Age=15552000', implode("\n", $first->getHeader('Set-Cookie')));

        // Signed out, back later, and the other button pressed by mistake.
        $login = (string) $this->get('/login', [self::LAST => $last])->getBody();
        self::assertMatchesRegularExpression('~Continue with Google</a> <small>\(you used this last time~', $login);
        self::assertSame(1, substr_count($login, 'you used this last time'));

        $second = $this->signInResponse('twitch', 't-1', [self::LAST => $last]);
        self::assertSame('/account?notice=welcome&last=google', $second->getHeaderLine('Location'));
        self::assertSame('twitch', self::cookieFrom($second, self::LAST));

        $cookies = [self::SESSION => self::cookieFrom($second, self::SESSION)];
        $page = (string) $this->get('/account?notice=welcome&last=google', $cookies)->getBody();
        self::assertStringContainsString('Last time you signed in here with Google.', $page);
        self::assertStringContainsString('action="/account/combine/google"', $page);
        self::assertStringNotContainsString('<h2>Combine with another account</h2>', $page, 'no second set of buttons');

        // Without the hint: a plain welcome still offers every other provider.
        $plain = (string) $this->get('/account?notice=welcome', $cookies)->getBody();
        self::assertStringContainsString('signed in here before with another provider', $plain);
        // A forged hint for a provider the account already has is ignored.
        self::assertStringNotContainsString('Last time', (string) $this->get('/account?notice=welcome&last=twitch', $cookies)->getBody());
    }

    public function testTheSameProviderAgainGetsNoHint(): void
    {
        $this->signIn('google', 'g-1');
        $response = $this->signInResponse('google', 'g-2', [self::LAST => 'google']);

        self::assertSame('/account?notice=welcome', $response->getHeaderLine('Location'));
    }

    public function testCombiningMovesEverythingIntoTheOlderAccount(): void
    {
        $older = $this->signIn('google', 'g-1');
        $olderId = $this->userOf('google', 'g-1');
        $this->post('/account/name', ['_csrf' => $this->csrf($older), 'display_name' => 'Chosen Name'], $older);
        $newer = $this->signIn('twitch', 't-1');
        $newerId = $this->userOf('twitch', 't-1');

        $both = $this->article('both');
        $onlyOlder = $this->article('only-older');
        $onlyNewer = $this->article('only-newer');
        $this->like($both, $olderId);
        $this->like($both, $newerId);
        $this->like($onlyOlder, $olderId);
        $this->like($onlyNewer, $newerId);
        $this->comment($both, $olderId, 'from the older');
        $this->comment($both, $newerId, 'from the newer');

        // Started from the newer account, as on the welcome page.
        $response = $this->combine($newer, 'google', 'g-1');

        self::assertSame('/account?notice=combined', $response->getHeaderLine('Location'));
        self::assertSame($olderId, $this->userOf('twitch', 't-1'));
        self::assertSame($olderId, $this->userOf('google', 'g-1'));
        self::assertSame(2, $this->number('SELECT count(*) FROM comment WHERE user_id = ?', $olderId));
        self::assertSame(3, $this->number('SELECT count(*) FROM vote WHERE user_id = ?', $olderId));
        self::assertSame(3, $this->number('SELECT count(*) FROM vote'), 'the like given twice counts once');
        self::assertSame('Chosen Name', $this->scalar('SELECT display_name FROM app_user WHERE id = ?', $olderId));
        self::assertSame(Accounts::DELETED_NAME, $this->scalar('SELECT display_name FROM app_user WHERE id = ? AND deleted_at IS NOT NULL', $newerId));

        // The browser is now signed in to the surviving account; the old session is gone.
        $session = [self::SESSION => self::cookieFrom($response, self::SESSION)];
        self::assertStringContainsString('Chosen Name', (string) $this->get('/account', $session)->getBody());
        self::assertSame('/login', $this->get('/account', $newer)->getHeaderLine('Location'));
        self::assertSame(200, $this->get('/account', $older)->getStatusCode());

        // Either login now reaches the one account.
        self::assertSame('/', $this->signInResponse('twitch', 't-1')->getHeaderLine('Location'));
        self::assertSame(2, $this->number('SELECT count(*) FROM app_user'));
    }

    public function testCombiningFromTheOlderAccountKeepsItsSession(): void
    {
        $older = $this->signIn('google', 'g-1');
        $this->signIn('twitch', 't-1');

        $response = $this->combine($older, 'twitch', 't-1');

        self::assertSame('/account?notice=combined', $response->getHeaderLine('Location'));
        self::assertSame([], array_filter($response->getHeader('Set-Cookie'), static fn(string $h): bool => str_starts_with($h, self::SESSION . '=')));
        self::assertSame(200, $this->get('/account', $older)->getStatusCode());
    }

    public function testBansCarryOverAndTrustNeedsACleanRecord(): void
    {
        $accounts = $this->service(Accounts::class);
        $future = $this->clock->now() + 86400;

        $a = $accounts->findOrCreate(new ProviderIdentity('google', 'a', 'A'))['id'];
        $b = $accounts->findOrCreate(new ProviderIdentity('twitch', 'b', 'B'))['id'];
        $this->pdo->prepare('UPDATE app_user SET trusted = true WHERE id = ?')->execute([$a]);
        $this->pdo->prepare('UPDATE app_user SET banned_until = to_timestamp(?) WHERE id = ?')->execute([$future, $b]);

        self::assertSame(['result' => 'combined', 'userId' => $a], $accounts->combine($a, new ProviderIdentity('twitch', 'b', 'B')));
        self::assertSame($future, $this->number('SELECT extract(epoch FROM banned_until) FROM app_user WHERE id = ?', $a), 'merging never lifts a ban');
        self::assertFalse($this->scalar('SELECT trusted FROM app_user WHERE id = ?', $a));

        $c = $accounts->findOrCreate(new ProviderIdentity('google', 'c', 'C'))['id'];
        $d = $accounts->findOrCreate(new ProviderIdentity('twitch', 'd', 'D'))['id'];
        $this->pdo->prepare('UPDATE app_user SET trusted = true, banned_until = to_timestamp(1) WHERE id = ?')->execute([$d]);

        self::assertSame(['result' => 'combined', 'userId' => $c], $accounts->combine($d, new ProviderIdentity('google', 'c', 'C')));
        self::assertTrue($this->scalar('SELECT trusted FROM app_user WHERE id = ?', $c), 'an expired ban doesn’t count');
    }

    public function testAccountsWithLoginsOfTheSameProviderAreNotCombined(): void
    {
        $mine = $this->signIn('google', 'g-1');
        $accounts = $this->service(Accounts::class);
        $other = $accounts->findOrCreate(new ProviderIdentity('twitch', 't-2', 'Other'))['id'];
        $accounts->link($other, new ProviderIdentity('google', 'g-2', 'Other'));

        $response = $this->combine($mine, 'twitch', 't-2');

        self::assertSame('/account?notice=combine-conflict', $response->getHeaderLine('Location'));
        self::assertSame($other, $this->userOf('twitch', 't-2'));
        self::assertSame(0, $this->number('SELECT count(*) FROM app_user WHERE deleted_at IS NOT NULL'));
    }

    public function testTheAdminIsNeverCombined(): void
    {
        $this->service(AdminAccount::class)->create('sovereign', 'correct horse battery');
        $this->service(Accounts::class)->allowForAdmin('twitch', 'owner');
        $reader = $this->signIn('google', 'g-1');

        $response = $this->combine($reader, 'twitch', 'owner');

        self::assertSame('/account?notice=combine-admin', $response->getHeaderLine('Location'));
        self::assertSame(1, $this->number("SELECT count(*) FROM user_identity WHERE provider = 'twitch' AND user_id = (SELECT id FROM app_user WHERE is_admin)"));
        self::assertSame(200, $this->get('/account', $reader)->getStatusCode());
    }

    public function testCombiningWithALoginThatHasNoAccountJustAddsIt(): void
    {
        $cookies = $this->signIn('google', 'g-1');

        $response = $this->combine($cookies, 'twitch', 'fresh');

        self::assertSame('/account?notice=linked', $response->getHeaderLine('Location'));
        self::assertSame($this->userOf('google', 'g-1'), $this->userOf('twitch', 'fresh'));
        self::assertSame(1, $this->number('SELECT count(*) FROM app_user'));
    }

    public function testCombiningNeedsTheTokenAndAReader(): void
    {
        $cookies = $this->signIn('google', 'g-1');
        self::assertSame(403, $this->post('/account/combine/twitch', [], $cookies)->getStatusCode());
        self::assertSame(404, $this->post('/account/combine/myspace', ['_csrf' => $this->csrf($cookies)], $cookies)->getStatusCode());
    }

    public function testAFlowWithoutTheCombineFlagStillParses(): void
    {
        $json = '{"provider":"google","state":"s","nonce":"n","verifier":"v","next":"/","linkUserId":3,"expiresAt":9}';
        $flow = FlowState::fromJson($json);
        self::assertNotNull($flow);
        self::assertFalse($flow->combine);
        self::assertFalse(FlowState::begin('google', '/', null, 9, true)->combine, 'a sign-in can never combine');
        self::assertNull(FlowState::fromJson(str_replace('}', ',"combine":"yes"}', $json)));
    }

    public function testReadersCanRemoveALoginButNotTheLastOne(): void
    {
        $cookies = $this->signIn('google', 'g-1');
        $id = $this->userOf('google', 'g-1');
        $this->service(Accounts::class)->link($id, new ProviderIdentity('twitch', 't-1', 'x'));
        $csrf = $this->csrf($cookies);

        self::assertStringContainsString('Remove Twitch login', (string) $this->get('/account', $cookies)->getBody());
        self::assertSame(403, $this->post('/account/unlink/twitch', [], $cookies)->getStatusCode());
        self::assertSame('/account?notice=unlinked', $this->post('/account/unlink/twitch', ['_csrf' => $csrf], $cookies)->getHeaderLine('Location'));
        self::assertSame('/account?notice=not-linked', $this->post('/account/unlink/twitch', ['_csrf' => $csrf], $cookies)->getHeaderLine('Location'));
        self::assertSame('/account?notice=last-login', $this->post('/account/unlink/google', ['_csrf' => $csrf], $cookies)->getHeaderLine('Location'));
        self::assertSame(1, $this->number('SELECT count(*) FROM user_identity'));
        self::assertStringNotContainsString('Remove Google login', (string) $this->get('/account', $cookies)->getBody());
    }

    public function testDeletingTheAccountForgetsTheProvider(): void
    {
        $cookies = $this->signIn('google', 'g-1');

        $response = $this->post('/account/delete', ['_csrf' => $this->csrf($cookies), 'confirm' => 'yes'], $cookies);

        self::assertSame('', self::cookieFrom($response, self::LAST));
    }
}
