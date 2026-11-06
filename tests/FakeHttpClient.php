<?php

declare(strict_types=1);

namespace Edict\Tests;

use Edict\Auth\OAuth\HttpClient;
use Edict\Auth\OAuth\OAuthException;

/**
 * Stands in for the provider's token endpoint. Tests set the claims (or a raw
 * response, or a failure) that the next token request receives.
 */
final class FakeHttpClient implements HttpClient
{
    /** @var array<string, mixed> */
    public array $claims = [];

    /** @var array{status: int, body: string}|null */
    public ?array $rawResponse = null;

    public bool $unreachable = false;

    /** @var list<array{url: string, fields: array<string, string>}> */
    public array $requests = [];

    public function postForm(string $url, array $fields): array
    {
        $this->requests[] = ['url' => $url, 'fields' => $fields];

        if ($this->unreachable) {
            throw new OAuthException('Could not reach provider');
        }

        return $this->rawResponse ?? ['status' => 200, 'body' => json_encode([
            'access_token' => 'discarded',
            'id_token' => self::idToken($this->claims),
        ], JSON_THROW_ON_ERROR)];
    }

    /**
     * @param array<string, mixed> $claims
     */
    public static function idToken(array $claims): string
    {
        $encode = static fn(string $s): string => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');

        return $encode('{"alg":"none"}') . '.' . $encode(json_encode($claims, JSON_THROW_ON_ERROR)) . '.signature';
    }
}
