<?php

declare(strict_types=1);

namespace Edict\Http;

use Edict\Auth\Session;
use Edict\Content\Articles;
use Edict\Http\Middleware\SessionMiddleware;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;
use Twig\Environment;

/**
 * The public site: home, archive, tag pages and articles.
 */
final readonly class PublicController
{
    private const HOME_ARTICLES = 10;
    private const PER_PAGE = 20;

    public function __construct(private Environment $twig, private Articles $articles) {}

    public function home(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->render($request, $response, 'public/home.html.twig', [
            'articles' => $this->articles->publicList(self::HOME_ARTICLES, 0),
            'has_more' => $this->articles->publicCount() > self::HOME_ARTICLES,
        ]);
    }

    public function archive(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $total = $this->articles->publicCount();
        $page = self::page($request, $total);

        return $this->render($request, $response, 'public/list.html.twig', [
            'heading' => 'Archive',
            'base_path' => '/archive',
            'articles' => $this->articles->publicList(self::PER_PAGE, ($page - 1) * self::PER_PAGE),
            'page' => $page,
            'last_page' => self::lastPage($total),
        ]);
    }

    /**
     * @param array<string, string> $args
     */
    public function tag(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $slug = $args['slug'] ?? '';
        $total = $this->articles->publicCountByTag($slug);
        $name = $this->articles->tagName($slug);
        if ($total === 0 || $name === null) {
            throw new HttpNotFoundException($request);
        }
        $page = self::page($request, $total);

        return $this->render($request, $response, 'public/list.html.twig', [
            'heading' => 'Tag: ' . $name,
            'base_path' => '/tag/' . $slug,
            'articles' => $this->articles->publicListByTag($slug, self::PER_PAGE, ($page - 1) * self::PER_PAGE),
            'page' => $page,
            'last_page' => self::lastPage($total),
        ]);
    }

    /**
     * @param array<string, string> $args
     */
    public function article(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $slug = $args['slug'] ?? '';
        $article = $this->articles->findPublic($slug);
        if ($article !== null) {
            return $this->render($request, $response, 'public/article.html.twig', ['article' => $article, 'preview' => false]);
        }

        $target = $this->articles->redirectTarget($slug);
        if ($target !== null) {
            return $response->withStatus(301)->withHeader('Location', '/' . $target);
        }

        throw new HttpNotFoundException($request);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function render(ServerRequestInterface $request, ResponseInterface $response, string $template, array $data): ResponseInterface
    {
        $response->getBody()->write($this->twig->render($template, $data + [
            'signed_in' => $request->getAttribute(SessionMiddleware::ATTRIBUTE) instanceof Session,
        ]));

        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    /**
     * A page beyond the end, or a non-number, doesn't exist. Page 1 always does.
     */
    private static function page(ServerRequestInterface $request, int $total): int
    {
        $raw = $request->getQueryParams()['page'] ?? '1';
        if (!is_string($raw) || preg_match('/^[1-9][0-9]{0,8}$/D', $raw) !== 1 || (int) $raw > self::lastPage($total)) {
            throw new HttpNotFoundException($request);
        }

        return (int) $raw;
    }

    private static function lastPage(int $total): int
    {
        return max(1, (int) ceil($total / self::PER_PAGE));
    }
}
