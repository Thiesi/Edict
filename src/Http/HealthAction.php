<?php

declare(strict_types=1);

namespace Edict\Http;

use Edict\Config;
use Edict\Database\ConnectionFactory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /healthz: used by the deploy script (S1) before and after flipping `current`.
 * Returns 200 when the app is up and, if configured, the database answers; 503 otherwise.
 * Never reveals connection details.
 */
final readonly class HealthAction
{
    public function __construct(private Config $config) {}

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

        $response->getBody()->write((string) json_encode(['status' => $healthy ? 'ok' : 'degraded', 'checks' => $checks]));

        return $response
            ->withStatus($healthy ? 200 : 503)
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Cache-Control', 'no-store');
    }
}
