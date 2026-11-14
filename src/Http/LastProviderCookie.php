<?php

declare(strict_types=1);

namespace Edict\Http;

use Edict\Auth\OAuth\ProviderRegistry;
use Edict\Config;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Remembers which provider this browser last signed in with, and nothing else,
 * so the sign-in and welcome pages can point people back to the account they
 * already have instead of letting them make a second one by mistake. It outlives
 * signing out on purpose, and goes when the account is deleted.
 */
final readonly class LastProviderCookie
{
    public const TTL = 180 * 86400;

    public function __construct(private Config $config) {}

    public function name(): string
    {
        return $this->config->secureCookies() ? '__Host-edict_last_provider' : 'edict_last_provider';
    }

    public function read(ServerRequestInterface $request): ?string
    {
        $value = $request->getCookieParams()[$this->name()] ?? null;

        return is_string($value) && in_array($value, ProviderRegistry::NAMES, true) ? $value : null;
    }

    public function set(ResponseInterface $response, string $provider): ResponseInterface
    {
        return $response->withAddedHeader('Set-Cookie', $this->header($provider, self::TTL));
    }

    public function clear(ResponseInterface $response): ResponseInterface
    {
        return $response->withAddedHeader('Set-Cookie', $this->header('', 0));
    }

    private function header(string $value, int $maxAge): string
    {
        return sprintf(
            '%s=%s; Path=/; Max-Age=%d; HttpOnly; SameSite=Lax%s',
            $this->name(),
            $value,
            $maxAge,
            $this->config->secureCookies() ? '; Secure' : '',
        );
    }
}
