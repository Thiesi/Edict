<?php

declare(strict_types=1);

namespace Edict;

/**
 * Typed, immutable application configuration, read once from the environment.
 */
final readonly class Config
{
    public function __construct(
        public string $projectDir,
        public string $env,
        public bool $debug,
        public ?string $databaseUrl,
        public string $appUrl,
        public string $appSecret,
    ) {}

    /**
     * @param array<string, mixed> $env
     */
    public static function fromEnvironment(string $projectDir, array $env): self
    {
        $appEnv = self::str($env, 'APP_ENV') ?? 'production';
        if (!in_array($appEnv, ['production', 'staging', 'dev', 'test'], true)) {
            throw new \InvalidArgumentException("APP_ENV must be production, staging, dev or test, got '$appEnv'");
        }

        $debug = in_array($appEnv, ['dev', 'test'], true);

        $appSecret = self::str($env, 'APP_SECRET');
        if ($appSecret === null && $debug) {
            $appSecret = 'insecure-development-secret-not-for-deployment';
        }
        if ($appSecret === null || strlen($appSecret) < 32) {
            throw new \InvalidArgumentException('APP_SECRET must be set and at least 32 characters long');
        }

        return new self(
            projectDir: $projectDir,
            env: $appEnv,
            debug: $debug,
            databaseUrl: self::str($env, 'DATABASE_URL'),
            appUrl: rtrim(self::str($env, 'APP_URL') ?? 'http://localhost:8080', '/'),
            appSecret: $appSecret,
        );
    }

    /**
     * Deployed environments use the __Host- cookie prefix, which browsers only
     * accept with Secure, Path=/ and no Domain. Local development is plain HTTP.
     */
    public function secureCookies(): bool
    {
        return !$this->debug;
    }

    public function cacheDir(): ?string
    {
        // Compiled-template cache only outside dev/test. It lives inside the
        // release directory, so it is bounded by the template count and
        // disappears with the release (see CLAUDE.md, storage rules).
        return $this->debug ? null : $this->projectDir . '/var/cache';
    }

    /**
     * @param array<string, mixed> $env
     */
    private static function str(array $env, string $key): ?string
    {
        $value = $env[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
