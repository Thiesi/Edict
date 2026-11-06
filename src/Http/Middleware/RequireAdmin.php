<?php

declare(strict_types=1);

namespace Edict\Http\Middleware;

use Edict\Auth\Session;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Guards the admin area: visitors go to the login page, logged-in non-admins get
 * a 403. Admin responses are never stored by caches.
 */
final readonly class RequireAdmin implements MiddlewareInterface
{
    public function __construct(private ResponseFactoryInterface $responses) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $session = $request->getAttribute(SessionMiddleware::ATTRIBUTE);

        if (!$session instanceof Session) {
            return $this->responses->createResponse(303)->withHeader('Location', '/admin/login');
        }

        if (!$session->isAdmin) {
            $response = $this->responses->createResponse(403);
            $response->getBody()->write('Forbidden.');

            return $response->withHeader('Content-Type', 'text/plain; charset=utf-8');
        }

        return $handler->handle($request)->withHeader('Cache-Control', 'no-store');
    }
}
