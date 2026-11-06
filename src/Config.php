<?php

declare(strict_types=1);

namespace Edict;

/**
 * Typed, immutable application configuration, read once from the environment.
 */
final readonly class Config
{
    /**
     * @param array<string, array{id: string, secret: string}> $oauthCredentials by provider name
     */
    public function __construct(
        public string $projectDir,
        public string $env,
        public bool $debug,
        public ?string $databaseUrl,
        public string $appUrl,
        public string $appSecret,
        public array $oauthCredentials = [],
        public ?string $dataDir = null,
        public int $mediaMaxBytes = 10_000_000_000,
        public int $mediaMaxFiles = 100_000,
        public int $mediaMinFreeBytes = 5_000_000_000,
        public string $vipsthumbnailBin = 'vipsthumbnail',
        public string $vipsheaderBin = 'vipsheader',
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
            oauthCredentials: self::oauthCredentials($env),
            dataDir: self::str($env, 'DATA_DIR'),
            mediaMaxBytes: self::int($env, 'MEDIA_MAX_BYTES', 10_000_000_000),
            mediaMaxFiles: self::int($env, 'MEDIA_MAX_FILES', 100_000),
            mediaMinFreeBytes: self::int($env, 'MEDIA_MIN_FREE_BYTES', 5_000_000_000),
            vipsthumbnailBin: self::str($env, 'VIPSTHUMBNAIL_BIN') ?? 'vipsthumbnail',
            vipsheaderBin: self::str($env, 'VIPSHEADER_BIN') ?? 'vipsheader',
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

    /**
     * The deployed release's name, written by deploy/edict-deploy.
     */
    public function release(): string
    {
        $release = @file_get_contents($this->projectDir . '/RELEASE');

        return $release === false ? 'unreleased' : trim($release);
    }

    /**
     * The commit this release was built from, for the small link on test deployments.
     * Production readers have no use for it, and a local checkout has no build.
     */
    public function testedCommit(): ?string
    {
        if ($this->env === 'production') {
            return null;
        }

        $sha = trim((string) @file_get_contents($this->projectDir . '/BUILD_SHA'));

        return preg_match('/^[0-9a-f]{40}$/D', $sha) === 1 ? $sha : null;
    }

    public function cacheDir(): ?string
    {
        // Compiled-template cache only outside dev/test. It lives inside the
        // release directory, so it is bounded by the template count and
        // disappears with the release (see CLAUDE.md, storage rules).
        return $this->debug ? null : $this->projectDir . '/var/cache';
    }

    /**
     * A provider counts as configured only with both its client ID and secret.
     *
     * @param array<string, mixed> $env
     *
     * @return array<string, array{id: string, secret: string}>
     */
    private static function oauthCredentials(array $env): array
    {
        $credentials = [];
        foreach (['google' => 'GOOGLE', 'twitch' => 'TWITCH'] as $name => $prefix) {
            $id = self::str($env, $prefix . '_OIDC_CLIENT_ID');
            $secret = self::str($env, $prefix . '_OIDC_CLIENT_SECRET');
            if ($id !== null && $secret !== null) {
                $credentials[$name] = ['id' => $id, 'secret' => $secret];
            }
        }

        return $credentials;
    }

    /**
     * @param array<string, mixed> $env
     */
    private static function int(array $env, string $key, int $default): int
    {
        $value = self::str($env, $key);
        if ($value === null) {
            return $default;
        }
        if (!ctype_digit($value)) {
            throw new \InvalidArgumentException("$key must be a whole number");
        }

        return (int) $value;
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
