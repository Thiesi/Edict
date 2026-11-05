<?php

declare(strict_types=1);

namespace Edict;

use DI\Container;
use DI\ContainerBuilder;
use Dotenv\Dotenv;
use Slim\App;
use Slim\Factory\AppFactory;

/**
 * Builds the Slim application: environment, container, middleware, routes.
 */
final class Kernel
{
    /**
     * @param array<string, string> $env overrides (tests); merged over the real environment
     *
     * @return App<Container>
     */
    public static function boot(string $projectDir, array $env = []): App
    {
        if (is_file($projectDir . '/.env')) {
            Dotenv::createImmutable($projectDir)->safeLoad();
        }

        $config = Config::fromEnvironment($projectDir, $env + $_ENV + getenv());

        $builder = new ContainerBuilder();
        $builder->addDefinitions(require $projectDir . '/config/container.php');
        $builder->addDefinitions([Config::class => $config]);
        $container = $builder->build();

        $app = AppFactory::createFromContainer($container);

        (require $projectDir . '/config/routes.php')($app);

        $app->addRoutingMiddleware();
        // Errors are logged except in tests, where log output would make tests risky.
        $app->addErrorMiddleware($config->debug, $config->env !== 'test', true);

        return $app;
    }
}
