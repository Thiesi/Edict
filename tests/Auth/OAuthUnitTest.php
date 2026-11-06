<?php

declare(strict_types=1);

namespace Edict\Tests\Auth;

use Edict\Auth\Accounts;
use Edict\Auth\LoginThrottle;
use Edict\Auth\OAuth\FlowState;
use Edict\Auth\OAuth\OAuthException;
use Edict\Auth\OAuth\ProviderRegistry;
use Edict\Auth\OAuth\StreamHttpClient;
use Edict\Config;
use Edict\Http\Middleware\RawCookieMiddleware;
use Edict\Http\SessionCookie;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;

final class OAuthUnitTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function nextPaths(): iterable
    {
        yield 'plain path' => ['/some-article', '/some-article'];
        yield 'path with query' => ['/a?b=c', '/a?b=c'];
        yield 'empty' => ['', '/'];
        yield 'absolute URL' => ['https://evil.example/', '/'];
        yield 'protocol-relative' => ['//evil.example', '/'];
        yield 'backslash' => ['/\evil.example', '/'];
        yield 'backslash later' => ['/a\b', '/'];
        yield 'header injection' => ["/a\r\nSet-Cookie: x=y", '/'];
        yield 'trailing newline' => ["/a\n", '/'];
        yield 'invalid UTF-8' => ["/\xFF", '/'];
        yield 'too long' => ['/' . str_repeat('a', 600), '/'];
    }

    #[DataProvider('nextPaths')]
    public function testOnlyLocalPathsAreRedirectTargets(string $next, string $expected): void
    {
        self::assertSame($expected, FlowState::safeNext($next));
    }

    /**
     * @return iterable<string, array{string, ?string}>
     */
    public static function names(): iterable
    {
        yield 'ordinary' => ['Ada Lovelace', 'Ada Lovelace'];
        yield 'umlauts' => ['Jürgen Müller', 'Jürgen Müller'];
        yield 'surrounding and repeated space' => ["  Ada \t  Lovelace ", 'Ada Lovelace'];
        yield 'tab between words' => ["Ada\tLovelace", 'Ada Lovelace'];
        yield 'zero-width' => ["Ad\u{200B}a", 'Ada'];
        yield 'text direction override' => ["\u{202E}adA", 'adA'];
        yield 'only whitespace' => ['   ', null];
        yield 'only invisible' => ["\u{200B}\u{200D}", null];
        yield 'invalid UTF-8' => ["\xFF\xFE", null];
    }

    #[DataProvider('names')]
    public function testNamesAreCleaned(string $input, ?string $expected): void
    {
        self::assertSame($expected, Accounts::cleanName($input));
    }

    public function testFlowStateSurvivesJsonAndRejectsGarbage(): void
    {
        $flow = FlowState::begin('google', '/x', 7, 123);
        $copy = FlowState::fromJson($flow->toJson());

        self::assertEquals($flow, $copy);
        self::assertNull(FlowState::fromJson('not json'));
        self::assertNull(FlowState::fromJson('{"provider":"google"}'));
    }

    public function testChallengeMatchesRfc7636Example(): void
    {
        $flow = new FlowState('google', 's', 'n', 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk', '/', null, 0);

        self::assertSame('E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM', $flow->challenge());
    }

    public function testProviderNeedsBothCredentials(): void
    {
        $config = Config::fromEnvironment('/x', [
            'APP_ENV' => 'test',
            'GOOGLE_OIDC_CLIENT_ID' => 'id',
            'TWITCH_OIDC_CLIENT_ID' => 'id',
            'TWITCH_OIDC_CLIENT_SECRET' => 'secret',
        ]);

        self::assertSame(['twitch'], array_keys((new ProviderRegistry($config))->all()));
    }

    public function testEncodedCookieNamesDoNotPassForTheRealOnes(): void
    {
        $config = Config::fromEnvironment('/x', ['APP_ENV' => 'production', 'APP_SECRET' => str_repeat('k', 32)]);
        $cookie = new SessionCookie($config);
        $read = static fn(string $header): ?string => $cookie->read(
            (new ServerRequestFactory())->createServerRequest('GET', '/')
                ->withCookieParams(RawCookieMiddleware::parse($header)),
        );

        self::assertNull($read('%5F%5FHost-edict_session=planted'));
        self::assertNull($read('edict_session=wrong-name'));
        self::assertSame('real', $read('%5F%5FHost-edict_session=planted; __Host-edict_session=real'));
        self::assertSame('a=b', $read('other=1;  __Host-edict_session=a=b; flag'));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function addresses(): iterable
    {
        yield 'IPv4' => ['192.0.2.1', '192.0.2.1'];
        yield 'IPv4-mapped' => ['::ffff:192.0.2.1', '192.0.2.1'];
        yield 'IPv6' => ['2001:db8:1:2:3:4:5:6', '20010db800010002::/64'];
        yield 'same IPv6 /64' => ['2001:db8:1:2:ffff::1', '20010db800010002::/64'];
        yield 'not an address' => ['unknown', 'unknown'];
    }

    #[DataProvider('addresses')]
    public function testThrottleGroupsAddressesByNetwork(string $ip, string $network): void
    {
        self::assertSame($network, LoginThrottle::network($ip));
    }

    public function testUnreachableHostRaisesOAuthException(): void
    {
        $this->expectException(OAuthException::class);
        (new StreamHttpClient())->postForm('http://127.0.0.1:1/token', ['a' => 'b']);
    }
}
