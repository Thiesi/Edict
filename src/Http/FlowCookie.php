<?php

declare(strict_types=1);

namespace Edict\Http;

use Edict\Auth\OAuth\FlowState;
use Edict\Auth\SecretBox;
use Edict\Clock;
use Edict\Config;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Binds a login attempt to the browser that started it: the provider's callback
 * is only accepted together with this cookie, which blocks login CSRF. It is
 * encrypted because it holds the PKCE verifier. SameSite=Lax lets it through on
 * the top-level redirect back from the provider.
 */
final readonly class FlowCookie
{
    public const TTL = 600;

    public function __construct(private Config $config, private SecretBox $box, private Clock $clock) {}

    public function name(): string
    {
        return $this->config->secureCookies() ? '__Host-edict_oauth' : 'edict_oauth';
    }

    public function begin(string $provider, string $next, ?int $linkUserId): FlowState
    {
        return FlowState::begin($provider, $next, $linkUserId, $this->clock->now() + self::TTL);
    }

    public function set(ResponseInterface $response, FlowState $flow): ResponseInterface
    {
        // Cookie-safe base64: PHP would decode a "+" in a cookie value to a space.
        $value = strtr($this->box->seal($flow->toJson()), '+/=', '-_.');

        return $response->withAddedHeader('Set-Cookie', $this->header($value, self::TTL));
    }

    public function read(ServerRequestInterface $request): ?FlowState
    {
        $value = $request->getCookieParams()[$this->name()] ?? null;
        if (!is_string($value) || $value === '') {
            return null;
        }

        try {
            $flow = FlowState::fromJson($this->box->open(strtr($value, '-_.', '+/=')));
        } catch (\RuntimeException) {
            return null;
        }

        return $flow !== null && $flow->expiresAt > $this->clock->now() ? $flow : null;
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
