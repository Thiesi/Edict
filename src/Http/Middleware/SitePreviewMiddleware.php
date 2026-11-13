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
        if (!is_string($theme) || !$session instanceof Session || !$session->isAdmin) {
            // Without a preview the settings aren't needed here, nor a database connection.
            return $handler->handle($request);
        }

        $settings = $this->services->get(Settings::class);
        $settings->preview($theme);
        try {
            return $handler->handle($request);
        } finally {
            // A preview never outlives the page it was asked for.
            $settings->preview(null);
        }
    }
}
