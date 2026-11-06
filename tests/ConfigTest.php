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

    public function testDevelopmentHasAFallbackSecretAndPlainCookies(): void
    {
        $config = Config::fromEnvironment('/x', ['APP_ENV' => 'dev', 'APP_URL' => 'http://localhost:8080/']);

        self::assertFalse($config->secureCookies());
        self::assertSame('http://localhost:8080', $config->appUrl);
    }
}
