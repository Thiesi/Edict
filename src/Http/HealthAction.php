<?php

declare(strict_types=1);

namespace Edict\Http;

use Edict\Config;
use Edict\Database\ConnectionFactory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Twig\Environment;

/**
 * GET /healthz: polled by the deploy script after flipping `current`. It also names
 * the release, which shows when the workers have really switched.
 * Returns 200 when the app is up and, if configured, the database answers; 503 otherwise.
 * Never reveals connection details.
 */
final readonly class HealthAction
{
    public function __construct(private Config $config, private Environment $twig) {}

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $checks = ['app' => 'ok'];
        $healthy = true;

        if ($this->config->databaseUrl !== null) {
            try {
                ConnectionFactory::fromUrl($this->config->databaseUrl)->query('SELECT 1');
                $checks['database'] = 'ok';
            } catch (\Throwable) {
                $checks['database'] = 'unavailable';
                $healthy = false;
            }
        } else {
            $checks['database'] = 'not configured';
        }

        // The pool can't write to the release, so a template missing from the deploy's
        // compiled cache (say, CLI and pool on different PHP versions) fails here.
        try {
            $this->twig->load('base.html.twig');
            $checks['templates'] = 'ok';
        } catch (\Throwable) {
            $checks['templates'] = 'unavailable';
            $healthy = false;
        }

        $response->getBody()->write((string) json_encode([
            'status' => $healthy ? 'ok' : 'degraded',
            'release' => $this->config->release(),
            'checks' => $checks,
        ]));

        return $response
            ->withStatus($healthy ? 200 : 503)
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Cache-Control', 'no-store');
    }
}
