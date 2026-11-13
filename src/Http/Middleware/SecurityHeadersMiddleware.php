<?php

declare(strict_types=1);

namespace Edict\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * A strict Content-Security-Policy and its companions on every page the app sends.
 * Edict has no inline scripts or styles and loads nothing from elsewhere, so an
 * injected script would have nowhere to come from. Apache adds HSTS, nosniff and
 * X-Frame-Options for the whole host; /media/ has its own, stricter policy there.
 */
final readonly class SecurityHeadersMiddleware implements MiddlewareInterface
{
    public const POLICY = "default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self'; media-src 'self'; font-src 'self'; "
        . "connect-src 'self'; frame-src 'self'; frame-ancestors 'self'; object-src 'none'; base-uri 'none'; "
        // Linking another login posts a form that the sign-in providers' pages answer.
        . "form-action 'self' https://accounts.google.com https://id.twitch.tv";

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);
        if ($response->hasHeader('Content-Security-Policy')) {
            return $response;
        }

        return $response
            ->withHeader('Content-Security-Policy', self::POLICY)
            ->withHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->withHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=(), interest-cohort=()')
            ->withHeader('Cross-Origin-Opener-Policy', 'same-origin')
            ->withHeader('X-Content-Type-Options', 'nosniff');
    }
}
