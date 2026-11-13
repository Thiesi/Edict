<?php

declare(strict_types=1);

namespace Edict\Http;

use Edict\Auth\Session;
use Edict\Content\Article;
use Edict\Content\Articles;
use Edict\Content\Comments;
use Edict\Content\Votes;
use Edict\Http\Middleware\CsrfMiddleware;
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
    private const MAX_QUERY = 200;

    public function __construct(private Environment $twig, private Articles $articles, private Votes $votes, private Comments $comments) {}

    public function home(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $lang = self::lang($request);

        return $this->render($request, $response, 'public/home.html.twig', [
            'articles' => $this->articles->publicList(self::HOME_ARTICLES, 0, $lang),
            'has_more' => $this->articles->publicCount($lang) > self::HOME_ARTICLES,
            'lang_filter' => $lang,
            'base_path' => '/',
        ]);
    }

    public function archive(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $lang = self::lang($request);
        $total = $this->articles->publicCount($lang);
        $page = self::page($request, $total);

        return $this->render($request, $response, 'public/list.html.twig', [
            'heading' => 'Archive',
            'base_path' => '/archive',
            'articles' => $this->articles->publicList(self::PER_PAGE, ($page - 1) * self::PER_PAGE, $lang),
            'page' => $page,
            'last_page' => self::lastPage($total),
            'lang_filter' => $lang,
        ]);
    }

    public function search(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $raw = $request->getQueryParams()['q'] ?? '';
        $query = is_string($raw) ? trim(mb_substr(str_replace("\0", '', $raw), 0, self::MAX_QUERY)) : '';
        $total = $query === '' ? 0 : $this->articles->searchCount($query);
        $page = self::page($request, $total);

        return $this->render($request, $response, 'public/search.html.twig', [
            'query' => $query,
            'total' => $total,
            'results' => $query === '' ? [] : array_map(
                static fn(array $result): array => ['article' => $result['article'], 'excerpt' => self::excerpt($result['excerpt'])],
                $this->articles->search($query, self::PER_PAGE, ($page - 1) * self::PER_PAGE),
            ),
            'page' => $page,
            'last_page' => self::lastPage($total),
        ])->withHeader('X-Robots-Tag', 'noindex');
    }

    /**
     * The database marks the matches; everything else is the article's text, escaped.
     */
    private static function excerpt(string $marked): string
    {
        $html = htmlspecialchars($marked, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');

        return str_replace(["\x01", "\x02", "\x03"], ['<mark>', '</mark>', ' … '], $html);
    }

    private static function lang(ServerRequestInterface $request): ?string
    {
        $lang = $request->getQueryParams()['lang'] ?? null;

        return $lang === 'de' || $lang === 'en' ? $lang : null;
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
            return $this->showArticle($request, $response, $article);
        }

        $target = $this->articles->redirectTarget($slug);
        if ($target !== null) {
            return $response->withStatus(301)->withHeader('Location', '/' . $target);
        }

        throw new HttpNotFoundException($request);
    }

    /**
     * The article with its likes and comments. A refused comment comes back here, with
     * the reader's text and what was wrong.
     *
     * @param array{error?: string, text?: string, parent?: int|null} $comment
     */
    public function showArticle(ServerRequestInterface $request, ResponseInterface $response, Article $article, array $comment = []): ResponseInterface
    {
        $session = $request->getAttribute(SessionMiddleware::ATTRIBUTE);
        $session = $session instanceof Session ? $session : null;
        $isArticle = $article->kind === 'article';
        $query = $request->getQueryParams();
        $isAdmin = $session !== null && $session->isAdmin;
        $mode = $this->comments->modeOf($article->id);

        return $this->render($request, $response, 'public/article.html.twig', [
            'article' => $article,
            'preview' => false,
            'likes' => $isArticle ? $this->votes->tally($article->id, $session?->userId) : null,
            'session' => $session,
            'banned' => $session !== null && $this->votes->isBanned($session->userId),
            'csrf_field' => CsrfMiddleware::FIELD,
            'comments' => $isArticle ? [
                'thread' => $this->comments->thread($article->id, $session?->userId, $isAdmin),
                'count' => $this->comments->count($article->id),
                'status' => $this->comments->status($article, $mode),
                'mode' => $mode,
                'reply' => self::id($comment['parent'] ?? $query['reply'] ?? null),
                'edit' => $isAdmin ? self::id($query['edit'] ?? null) : null,
                'error' => $comment['error'] ?? null,
                'text' => $comment['text'] ?? '',
                'posted' => self::id($query['posted'] ?? null),
            ] : null,
        ]);
    }

    private static function id(mixed $value): ?int
    {
        return (is_int($value) && $value > 0) || (is_string($value) && preg_match('~^[1-9][0-9]{0,17}$~D', $value) === 1) ? (int) $value : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function render(ServerRequestInterface $request, ResponseInterface $response, string $template, array $data): ResponseInterface
    {
        $signedIn = $request->getAttribute(SessionMiddleware::ATTRIBUTE) instanceof Session;
        $response->getBody()->write($this->twig->render($template, $data + ['signed_in' => $signedIn]));
        $response = $response->withHeader('Content-Type', 'text/html; charset=utf-8');

        // A signed-in page carries the reader's own like and token: never for a shared cache.
        return $signedIn ? $response->withHeader('Cache-Control', 'private, no-store') : $response;
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
