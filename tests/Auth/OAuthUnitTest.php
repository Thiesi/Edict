<?php

declare(strict_types=1);

namespace Edict\Tests\Auth;

use Edict\Auth\Accounts;
use Edict\Auth\OAuth\FlowState;
use Edict\Auth\OAuth\OAuthException;
use Edict\Auth\OAuth\ProviderRegistry;
use Edict\Auth\OAuth\StreamHttpClient;
use Edict\Config;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

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

    public function testUnreachableHostRaisesOAuthException(): void
    {
        $this->expectException(OAuthException::class);
        (new StreamHttpClient())->postForm('http://127.0.0.1:1/token', ['a' => 'b']);
    }
}
