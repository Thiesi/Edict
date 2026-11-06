<?php

declare(strict_types=1);

namespace Edict\Auth\OAuth;

use Edict\Config;

/**
 * The login providers that have credentials configured. A provider without
 * credentials doesn't exist as far as the site is concerned.
 */
final readonly class ProviderRegistry
{
    public const NAMES = ['google', 'twitch'];

    public function __construct(private Config $config) {}

    /**
     * @return array<string, Provider>
     */
    public function all(): array
    {
        $providers = [];
        foreach (self::NAMES as $name) {
            $provider = $this->get($name);
            if ($provider !== null) {
                $providers[$name] = $provider;
            }
        }

        return $providers;
    }

    public function get(string $name): ?Provider
    {
        $credentials = $this->config->oauthCredentials[$name] ?? null;
        if ($credentials === null) {
            return null;
        }

        return match ($name) {
            'google' => new Provider(
                name: 'google',
                label: 'Google',
                clientId: $credentials['id'],
                clientSecret: $credentials['secret'],
                authorizeUrl: 'https://accounts.google.com/o/oauth2/v2/auth',
                tokenUrl: 'https://oauth2.googleapis.com/token',
                issuers: ['https://accounts.google.com', 'accounts.google.com'],
                nameClaim: 'name',
                // Lets people with several Google accounts choose one.
                authorizeParams: ['scope' => 'openid profile', 'prompt' => 'select_account'],
            ),
            'twitch' => new Provider(
                name: 'twitch',
                label: 'Twitch',
                clientId: $credentials['id'],
                clientSecret: $credentials['secret'],
                authorizeUrl: 'https://id.twitch.tv/oauth2/authorize',
                tokenUrl: 'https://id.twitch.tv/oauth2/token',
                issuers: ['https://id.twitch.tv/oauth2'],
                nameClaim: 'preferred_username',
                // Twitch only includes the name when it is asked for explicitly.
                authorizeParams: ['scope' => 'openid', 'claims' => '{"id_token":{"preferred_username":null}}'],
            ),
            default => null,
        };
    }
}
