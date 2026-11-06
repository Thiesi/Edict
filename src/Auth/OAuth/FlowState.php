<?php

declare(strict_types=1);

namespace Edict\Auth\OAuth;

/**
 * Everything one login attempt has to remember between leaving for the provider
 * and coming back. It travels in an encrypted cookie (see FlowCookie), so
 * anonymous requests never write to the database.
 */
final readonly class FlowState
{
    private const MAX_NEXT_LENGTH = 512;

    public function __construct(
        public string $provider,
        public string $state,
        public string $nonce,
        public string $verifier,
        public string $next,
        public ?int $linkUserId,
        public int $expiresAt,
    ) {}

    public static function begin(string $provider, string $next, ?int $linkUserId, int $expiresAt): self
    {
        return new self(
            $provider,
            self::random(),
            self::random(),
            self::random(),
            self::safeNext($next),
            $linkUserId,
            $expiresAt,
        );
    }

    /**
     * The PKCE S256 challenge for this attempt's verifier.
     */
    public function challenge(): string
    {
        return self::base64Url(hash('sha256', $this->verifier, true));
    }

    /**
     * Only same-site paths may be redirect targets. "//evil.example" and
     * "/\evil.example" are absolute URLs to a browser, so they fall back to "/".
     */
    public static function safeNext(string $next): string
    {
        if (strlen($next) > self::MAX_NEXT_LENGTH || preg_match('#^/(?![/\\\\])[^\\\\\x00-\x20\x7f]*$#Du', $next) !== 1) {
            return '/';
        }

        return $next;
    }

    public function toJson(): string
    {
        return json_encode(get_object_vars($this), JSON_THROW_ON_ERROR);
    }

    public static function fromJson(string $json): ?self
    {
        $data = json_decode($json, true);
        if (!is_array($data)) {
            return null;
        }

        $link = $data['linkUserId'] ?? null;
        if (
            !is_string($data['provider'] ?? null)
            || !is_string($data['state'] ?? null)
            || !is_string($data['nonce'] ?? null)
            || !is_string($data['verifier'] ?? null)
            || !is_string($data['next'] ?? null)
            || !is_int($data['expiresAt'] ?? null)
            || !($link === null || is_int($link))
        ) {
            return null;
        }

        return new self($data['provider'], $data['state'], $data['nonce'], $data['verifier'], $data['next'], $link, $data['expiresAt']);
    }

    private static function random(): string
    {
        return self::base64Url(random_bytes(32));
    }

    private static function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
