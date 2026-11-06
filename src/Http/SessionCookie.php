<?php

declare(strict_types=1);

namespace Edict\Http;

use Edict\Config;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The session cookie. SameSite=Lax rather than Strict, because Strict would drop
 * the cookie on the top-level redirect back from an OAuth provider. State-changing
 * requests are protected by CsrfMiddleware.
 */
final readonly class SessionCookie
{
    public function __construct(private Config $config) {}

    public function name(): string
    {
        return $this->config->secureCookies() ? '__Host-edict_session' : 'edict_session';
    }

    public function read(ServerRequestInterface $request): ?string
    {
        $value = $request->getCookieParams()[$this->name()] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function set(ResponseInterface $response, string $token, int $ttl): ResponseInterface
    {
        return $response->withAddedHeader('Set-Cookie', $this->header($token, $ttl));
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
