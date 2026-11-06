<?php

declare(strict_types=1);

namespace Edict\Http;

use Edict\Auth\Passkeys;
use Edict\Auth\SecretBox;
use Edict\Clock;
use Edict\Config;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Holds a passkey challenge between the two requests of a ceremony. It is encrypted
 * and bound to its purpose, and for registration to the session that passed the
 * password check, so it dies with that session.
 */
final readonly class ChallengeCookie
{
    public const LOGIN = 'login';
    public const REGISTER = 'register';

    public function __construct(private Config $config, private SecretBox $box, private Clock $clock) {}

    public function set(ResponseInterface $response, string $purpose, string $challenge, ?string $session): ResponseInterface
    {
        $value = $this->box->seal(json_encode([
            'purpose' => $purpose,
            'challenge' => Passkeys::encode($challenge),
            'session' => $session,
            'expires' => $this->clock->now() + Passkeys::CHALLENGE_TTL,
        ], JSON_THROW_ON_ERROR));

        return $response->withAddedHeader('Set-Cookie', $this->header(strtr($value, '+/=', '-_.'), Passkeys::CHALLENGE_TTL));
    }

    /**
     * @return string|null the binary challenge, if the cookie is valid for this purpose and session
     */
    public function read(ServerRequestInterface $request, string $purpose, ?string $session): ?string
    {
        $value = $request->getCookieParams()[$this->name()] ?? null;
        if (!is_string($value) || $value === '') {
            return null;
        }

        try {
            $data = json_decode($this->box->open(strtr($value, '-_.', '+/=')), true);
        } catch (\RuntimeException) {
            return null;
        }

        if (
            !is_array($data)
            || ($data['purpose'] ?? null) !== $purpose
            || ($data['session'] ?? null) !== $session
            || !is_int($data['expires'] ?? null)
            || $data['expires'] <= $this->clock->now()
            || !is_string($data['challenge'] ?? null)
        ) {
            return null;
        }

        return Passkeys::decode($data['challenge']);
    }

    public function clear(ResponseInterface $response): ResponseInterface
    {
        return $response->withAddedHeader('Set-Cookie', $this->header('', 0));
    }

    private function name(): string
    {
        return $this->config->secureCookies() ? '__Host-edict_passkey' : 'edict_passkey';
    }

    private function header(string $value, int $maxAge): string
    {
        return sprintf(
            '%s=%s; Path=/; Max-Age=%d; HttpOnly; SameSite=Strict%s',
            $this->name(),
            $value,
            $maxAge,
            $this->config->secureCookies() ? '; Secure' : '',
        );
    }
}
