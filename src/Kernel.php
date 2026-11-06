<?php

declare(strict_types=1);

namespace Edict;

use DI\Container;
use DI\ContainerBuilder;
use Dotenv\Dotenv;
use Edict\Http\Middleware\CsrfMiddleware;
use Edict\Http\Middleware\RawCookieMiddleware;
use Edict\Http\Middleware\SessionMiddleware;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;
use Slim\Exception\HttpMethodNotAllowedException;
use Slim\Exception\HttpNotFoundException;
use Slim\Factory\AppFactory;
use Slim\Interfaces\ErrorHandlerInterface;

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
        $errors = $app->addErrorMiddleware($config->debug, $config->env !== 'test', true);

        // Scanners probe for files all day. A 404 or 405 is an answer, not an error
        // worth a stack trace in the log, so these use the default handler unlogged.
        $default = $errors->getDefaultErrorHandler();
        if ($default instanceof ErrorHandlerInterface) {
            $errors->setErrorHandler(
                [HttpNotFoundException::class, HttpMethodNotAllowedException::class],
                // Not static: Slim binds handlers to the container.
                fn(ServerRequestInterface $request, \Throwable $error, bool $display): ResponseInterface => $default($request, $error, $display, false, false),
            );
        }

        return $app;
    }
}
