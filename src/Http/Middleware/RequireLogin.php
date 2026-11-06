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
 * Sends visitors to the sign-in page. Personal pages are never stored by caches.
 */
final readonly class RequireLogin implements MiddlewareInterface
{
    public function __construct(private ResponseFactoryInterface $responses) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!$request->getAttribute(SessionMiddleware::ATTRIBUTE) instanceof Session) {
            return $this->responses->createResponse(303)->withHeader('Location', '/login');
        }

        return $handler->handle($request)->withHeader('Cache-Control', 'no-store');
    }
}
