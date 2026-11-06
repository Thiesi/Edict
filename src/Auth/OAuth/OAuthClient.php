<?php

declare(strict_types=1);

namespace Edict\Auth\OAuth;

use Edict\Clock;
use Edict\Config;

/**
 * The OpenID Connect authorisation-code flow with PKCE. Only the ID token is
 * used. Access and refresh tokens are discarded and never stored.
 */
final readonly class OAuthClient
{
    public function __construct(private HttpClient $http, private Config $config, private Clock $clock) {}

    public function authorizationUrl(Provider $provider, FlowState $flow): string
    {
        return $provider->authorizeUrl . '?' . http_build_query([
            'response_type' => 'code',
            'client_id' => $provider->clientId,
            'redirect_uri' => $this->redirectUri($provider),
            'state' => $flow->state,
            'nonce' => $flow->nonce,
            'code_challenge' => $flow->challenge(),
            'code_challenge_method' => 'S256',
        ] + $provider->authorizeParams, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * @throws OAuthException
     */
    public function identify(Provider $provider, FlowState $flow, string $code): ProviderIdentity
    {
        $response = $this->http->postForm($provider->tokenUrl, [
            'grant_type' => 'authorization_code',
            'client_id' => $provider->clientId,
            'client_secret' => $provider->clientSecret,
            'code' => $code,
            'redirect_uri' => $this->redirectUri($provider),
            'code_verifier' => $flow->verifier,
        ]);

        $body = json_decode($response['body'], true);
        if ($response['status'] !== 200) {
            $error = is_array($body) && is_string($body['error'] ?? null) ? $body['error'] : 'no error code';

            throw new OAuthException(sprintf(
                '%s token endpoint answered %d (%s)',
                $provider->label,
                $response['status'],
                preg_replace('/[^\w .-]/', '', mb_substr($error, 0, 64)),
            ));
        }

        $idToken = is_array($body) ? ($body['id_token'] ?? null) : null;
        if (!is_string($idToken)) {
            throw new OAuthException($provider->label . ' token response has no ID token');
        }

        return $this->validate($provider, $flow, self::claims($idToken));
    }

    /**
     * The address the provider sends people back to. It must match the registered
     * one exactly, and those are registered with the lowercase host.
     */
    private function redirectUri(Provider $provider): string
    {
        return strtolower($this->config->appUrl) . '/auth/' . $provider->name . '/callback';
    }

    /**
     * Decodes the token without checking its signature. The token came straight
     * from the provider's token endpoint over verified TLS, which OpenID Connect
     * Core 3.1.3.7 accepts in place of the signature check. It must never be
     * accepted from any other source.
     *
     * @return array<mixed>
     */
    private static function claims(string $idToken): array
    {
        $parts = explode('.', $idToken);
        $json = count($parts) === 3 ? base64_decode(strtr($parts[1], '-_', '+/'), true) : false;
        $claims = $json === false ? null : json_decode($json, true);
        if (!is_array($claims)) {
            throw new OAuthException('Malformed ID token');
        }

        return $claims;
    }

    /**
     * @param array<mixed> $claims
     */
    private function validate(Provider $provider, FlowState $flow, array $claims): ProviderIdentity
    {
        $audience = $claims['aud'] ?? null;
        $audiences = is_array($audience) ? $audience : [$audience];
        $nonce = $claims['nonce'] ?? null;
        $expires = $claims['exp'] ?? null;
        $subject = $claims['sub'] ?? null;

        $failure = match (true) {
            !in_array($claims['iss'] ?? null, $provider->issuers, true) => 'issuer',
            !in_array($provider->clientId, $audiences, true) => 'audience',
            !is_int($expires) || $expires <= $this->clock->now() => 'expiry',
            !is_string($nonce) || !hash_equals($flow->nonce, $nonce) => 'nonce',
            default => null,
        };
        if ($failure !== null) {
            throw new OAuthException(sprintf('%s ID token failed the %s check', $provider->label, $failure));
        }
        if (!is_string($subject) || $subject === '') {
            throw new OAuthException($provider->label . ' ID token has no subject');
        }

        $name = $claims[$provider->nameClaim] ?? null;

        return new ProviderIdentity($provider->name, $subject, is_string($name) ? $name : '');
    }
}
