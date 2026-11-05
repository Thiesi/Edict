<?php

declare(strict_types=1);

namespace Edict\Http\Middleware;

use Edict\Auth\SessionStore;
use Edict\Http\SessionCookie;
use Edict\Services;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Attaches the current Edict\Auth\Session (or null) as the "session" request
 * attribute. Visitors without a session cookie cost no database query.
 */
final readonly class SessionMiddleware implements MiddlewareInterface
{
    public const ATTRIBUTE = 'session';

    public function __construct(private Services $services, private SessionCookie $cookie) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $token = $this->cookie->read($request);
        $session = null;
        if ($token !== null) {
            // Resolved only here, so the database connection stays lazy.
            $session = $this->services->get(SessionStore::class)->load($token);
        }

        return $handler->handle($request->withAttribute(self::ATTRIBUTE, $session));
    }
}
