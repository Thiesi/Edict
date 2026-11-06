<?php

declare(strict_types=1);

namespace Edict\Tests;

use Edict\Config;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    public function testDeployedEnvironmentsRequireALongSecret(): void
    {
        foreach (['production', 'staging'] as $env) {
            foreach ([[], ['APP_SECRET' => 'too short']] as $extra) {
                try {
                    Config::fromEnvironment('/x', ['APP_ENV' => $env] + $extra);
                    self::fail("$env accepted a missing or short APP_SECRET");
                } catch (\InvalidArgumentException $e) {
                    self::assertStringContainsString('APP_SECRET', $e->getMessage());
                }
            }
        }
    }

    public function testDefaultsToProductionWhenAppEnvIsUnset(): void
    {
        $config = Config::fromEnvironment('/x', ['APP_SECRET' => str_repeat('k', 32)]);

        self::assertSame('production', $config->env);
        self::assertFalse($config->debug);
        self::assertTrue($config->secureCookies());
    }

    public function testTheCommitLinkAppearsOnlyOnTestDeployments(): void
    {
        $sha = str_repeat('a1', 20);
        $dir = sys_get_temp_dir() . '/edict-config-test-' . bin2hex(random_bytes(4));
        mkdir($dir);

        try {
            $config = static fn(string $env): Config => Config::fromEnvironment($dir, ['APP_ENV' => $env, 'APP_SECRET' => str_repeat('k', 32)]);

            self::assertNull($config('staging')->testedCommit(), 'no build file');

            file_put_contents($dir . '/BUILD_SHA', "not a commit\n");
            self::assertNull($config('staging')->testedCommit(), 'bad build file');

            file_put_contents($dir . '/BUILD_SHA', "$sha\n");
            self::assertSame($sha, $config('staging')->testedCommit());
            self::assertNull($config('production')->testedCommit());
        } finally {
            @unlink($dir . '/BUILD_SHA');
            rmdir($dir);
        }
    }

    public function testDevelopmentHasAFallbackSecretAndPlainCookies(): void
    {
        $config = Config::fromEnvironment('/x', ['APP_ENV' => 'dev', 'APP_URL' => 'http://localhost:8080/']);

        self::assertFalse($config->secureCookies());
        self::assertSame('http://localhost:8080', $config->appUrl);
    }
}
