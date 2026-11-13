<?php

declare(strict_types=1);

namespace Edict\Http;

use Edict\Auth\Session;
use Edict\Content\Articles;
use Edict\Http\Middleware\CsrfMiddleware;
use Edict\Http\Middleware\SessionMiddleware;
use Edict\Site\Settings;
use Edict\Site\Themes;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Twig\Environment;

/**
 * The site's look and frame: the theme picker, and the name, menu and footer.
 * All routes sit behind RequireAdmin.
 */
final readonly class SiteController
{
    public function __construct(private Environment $twig, private Settings $settings, private Articles $articles) {}

    public function themes(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->html($request, $response, 'admin/themes.html.twig', [
            'themes' => Themes::ALL,
            'current' => $this->settings->site()->theme,
            'applied' => ($request->getQueryParams()['applied'] ?? null) === '1',
        ]);
    }

    public function applyTheme(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = $request->getParsedBody();
        $theme = is_array($body) ? ($body['theme'] ?? null) : null;
        if (!is_string($theme) || !$this->settings->applyTheme($theme)) {
            $response->getBody()->write('There is no such theme.');

            return $response->withStatus(422)->withHeader('Content-Type', 'text/plain; charset=utf-8');
        }

        return $response->withStatus(303)->withHeader('Location', '/admin/themes?applied=1');
    }

    public function show(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $site = $this->settings->site();

        return $this->form($request, $response, [
            'title' => $site->title,
            'description' => $site->description,
            'menu' => Settings::linesOf($site->menu),
            'footer' => Settings::linesOf($site->footer),
            'imprint' => $site->imprint,
            'privacy' => $site->privacy,
        ], [], ($request->getQueryParams()['saved'] ?? null) === '1');
    }

    public function save(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = $request->getParsedBody();
        $form = is_array($body) ? $body : [];
        $errors = $this->settings->save($form);
        if ($errors === []) {
            return $response->withStatus(303)->withHeader('Location', '/admin/site?saved=1');
        }

        return $this->form($request, $response->withStatus(422), $form, $errors, false);
    }

    /**
     * @param array<mixed> $values
     * @param array<string, string> $errors
     */
    private function form(ServerRequestInterface $request, ResponseInterface $response, array $values, array $errors, bool $saved): ResponseInterface
    {
        return $this->html($request, $response, 'admin/site.html.twig', [
            'values' => $values,
            'errors' => $errors,
            'saved' => $saved,
            'pages' => $this->articles->publishedPages(),
            'max_links' => Settings::MAX_LINKS,
        ]);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function html(ServerRequestInterface $request, ResponseInterface $response, string $template, array $data): ResponseInterface
    {
        $session = $request->getAttribute(SessionMiddleware::ATTRIBUTE);
        $response->getBody()->write($this->twig->render($template, $data + [
            'csrf_field' => CsrfMiddleware::FIELD,
            'csrf_token' => $session instanceof Session ? $session->csrfToken : '',
        ]));

        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }
}
