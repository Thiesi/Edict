<?php

declare(strict_types=1);

namespace Edict\Http\Middleware;

use Edict\Auth\Session;
use Edict\Services;
use Edict\Site\Settings;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * "?theme=quiet" shows a page in another theme, to the admin only, for the theme
 * picker's previews. Everyone else gets the theme that is applied.
 */
final readonly class SitePreviewMiddleware implements MiddlewareInterface
{
    public function __construct(private Services $services) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $theme = $request->getQueryParams()['theme'] ?? null;
        $session = $request->getAttribute(SessionMiddleware::ATTRIBUTE);
        if (is_string($theme) && $session instanceof Session && $session->isAdmin) {
            $this->services->get(Settings::class)->preview($theme);
        }

        return $handler->handle($request);
    }
}
