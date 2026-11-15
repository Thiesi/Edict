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
use Slim\Exception\HttpException;

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
        // A preview never outlives the page it was asked for.
        $settings->preview($theme);
        try {
            $response = $handler->handle($request);
        } catch (HttpException $answer) {
            // A 404 or 403 is still that page: ErrorPage draws it in the preview, then ends it.
            throw $answer;
        } catch (\Throwable $fault) {
            $settings->preview(null);
            throw $fault;
        }
        $settings->preview(null);

        return $response;
    }
}
