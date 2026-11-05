<?php

declare(strict_types=1);

namespace Edict\Http\Middleware;

use Edict\Auth\Session;
use Edict\Config;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Protects every state-changing request, in two layers:
 * - The Origin header, when sent, must match APP_URL. This also covers forms
 *   without a session, such as the login form.
 * - With a session, the request must carry its CSRF token, in the _csrf form
 *   field or the X-CSRF-Token header.
 */
final readonly class CsrfMiddleware implements MiddlewareInterface
{
    public const FIELD = '_csrf';

    public function __construct(private Config $config, private ResponseFactoryInterface $responses) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (in_array($request->getMethod(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return $handler->handle($request);
        }

        if (!$this->originAllowed($request) || !$this->tokenValid($request)) {
            $response = $this->responses->createResponse(403);
            $response->getBody()->write('Forbidden: request failed the CSRF check.');

            return $response->withHeader('Content-Type', 'text/plain; charset=utf-8');
        }

        return $handler->handle($request);
    }

    private function originAllowed(ServerRequestInterface $request): bool
    {
        $origin = $request->getHeaderLine('Origin');

        // Hostnames are case-insensitive: the configured and the sent one may differ in case.
        return $origin === '' || strcasecmp(rtrim($origin, '/'), $this->config->appUrl) === 0;
    }

    private function tokenValid(ServerRequestInterface $request): bool
    {
        $session = $request->getAttribute(SessionMiddleware::ATTRIBUTE);
        if (!$session instanceof Session) {
            return true;
        }

        $body = $request->getParsedBody();
        $sent = is_array($body) && is_string($body[self::FIELD] ?? null)
            ? $body[self::FIELD]
            : $request->getHeaderLine('X-CSRF-Token');

        return hash_equals($session->csrfToken, $sent);
    }
}
