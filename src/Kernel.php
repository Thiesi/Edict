<?php

declare(strict_types=1);

namespace Edict;

use DI\Container;
use DI\ContainerBuilder;
use Dotenv\Dotenv;
use Edict\Http\Middleware\CsrfMiddleware;
use Edict\Http\Middleware\RawCookieMiddleware;
use Edict\Http\Middleware\SessionMiddleware;
use Slim\App;
use Slim\Factory\AppFactory;

/**
 * Builds the Slim application: environment, container, middleware, routes.
 */
final class Kernel
{
    /**
     * @param array<string, string> $env overrides (tests); merged over the real environment
     * @param array<string, mixed> $definitions container overrides (tests)
     *
     * @return App<Container>
     */
    public static function boot(string $projectDir, array $env = [], array $definitions = []): App
    {
        if (is_file($projectDir . '/.env')) {
            Dotenv::createImmutable($projectDir)->safeLoad();
        }

        $config = Config::fromEnvironment($projectDir, $env + $_ENV + getenv());

        $builder = new ContainerBuilder();
        $builder->addDefinitions(require $projectDir . '/config/container.php');
        $builder->addDefinitions([Config::class => $config]);
        $builder->addDefinitions($definitions);
        $container = $builder->build();

        $app = AppFactory::createFromContainer($container);

        (require $projectDir . '/config/routes.php')($app);

        // Slim runs the last-added middleware first: errors, routing, body parsing, raw cookies, session, CSRF.
        $app->add(CsrfMiddleware::class);
        $app->add(SessionMiddleware::class);
        $app->add(RawCookieMiddleware::class);
        $app->addBodyParsingMiddleware();
        $app->addRoutingMiddleware();
        // Errors are logged except in tests, where log output would make tests risky.
        $app->addErrorMiddleware($config->debug, $config->env !== 'test', true);

        return $app;
    }
}
