<?php

declare(strict_types=1);

namespace Edict\Http;

use Edict\Auth\Session;
use Edict\Content\Article;
use Edict\Content\ArticleInput;
use Edict\Content\Articles;
use Edict\Content\Body;
use Edict\Content\SlugTaken;
use Edict\Http\Middleware\CsrfMiddleware;
use Edict\Http\Middleware\SessionMiddleware;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;
use Twig\Environment;

/**
 * The article list and form. All routes sit behind RequireAdmin.
 */
final readonly class ArticleAdminController
{
    public function __construct(private Environment $twig, private Articles $articles) {}

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $trash = ($request->getQueryParams()['trash'] ?? null) === '1';
        if ($trash) {
            $this->articles->purgeTrash();
        }

        return $this->html($request, $response, 'admin/articles.html.twig', [
            'articles' => $this->articles->adminList($trash),
            'trash' => $trash,
            'trash_days' => Articles::TRASH_DAYS,
        ]);
    }

    public function new(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->form($request, $response, null, new ArticleInput('', '', $this->articles->lastLanguage(), '', '', ''), []);
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $input = ArticleInput::fromForm($request->getParsedBody());
        $errors = $input->errors();
        if ($errors === []) {
            try {
                return self::toEdit($response, $this->articles->create($input));
            } catch (SlugTaken) {
                $errors['slug'] = 'That address is already in use.';
            }
        }

        return $this->form($request, $response->withStatus(422), null, $input, $errors);
    }

    /**
     * @param array<string, string> $args
     */
    public function edit(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $article = $this->article($request, $args);

        return $this->form($request, $response, $article, new ArticleInput(
            $article->title,
            $article->slug,
            $article->lang,
            $article->teaser,
            Body::toPlainText($article->bodyJson),
            $article->tagNames(),
        ), []);
    }

    /**
     * @param array<string, string> $args
     */
    public function update(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $article = $this->article($request, $args);
        $input = ArticleInput::fromForm($request->getParsedBody());
        $errors = $input->errors();
        if ($errors === []) {
            try {
                $this->articles->update($article, $input);

                return self::toEdit($response, $article->id);
            } catch (SlugTaken) {
                $errors['slug'] = 'That address is already in use.';
            }
        }

        return $this->form($request, $response->withStatus(422), $article, $input, $errors);
    }

    /**
     * @param array<string, string> $args
     */
    public function publish(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $article = $this->article($request, $args);
        $this->articles->publish($article->id);

        return self::toEdit($response, $article->id);
    }

    /**
     * @param array<string, string> $args
     */
    public function unpublish(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $article = $this->article($request, $args);
        $this->articles->unpublish($article->id);

        return self::toEdit($response, $article->id);
    }

    /**
     * @param array<string, string> $args
     */
    public function trash(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $this->articles->trash($this->article($request, $args)->id);

        return $response->withStatus(303)->withHeader('Location', '/admin/articles');
    }

    /**
     * @param array<string, string> $args
     */
    public function restore(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $article = $this->article($request, $args);
        $this->articles->restore($article->id);

        return self::toEdit($response, $article->id);
    }

    /**
     * @param array<string, string> $args
     */
    public function delete(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $article = $this->article($request, $args);
        $body = $request->getParsedBody();
        if (is_array($body) && ($body['confirm'] ?? null) === 'yes') {
            $this->articles->delete($article->id);
        }

        return $response->withStatus(303)->withHeader('Location', '/admin/articles?trash=1');
    }

    /**
     * The public template, so a draft looks exactly as readers will see it.
     *
     * @param array<string, string> $args
     */
    public function preview(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        return $this->html($request, $response, 'public/article.html.twig', [
            'article' => $this->article($request, $args),
            'preview' => true,
            'signed_in' => true,
        ])->withHeader('X-Robots-Tag', 'noindex');
    }

    /**
     * @param array<string, string> $errors
     */
    private function form(ServerRequestInterface $request, ResponseInterface $response, ?Article $article, ArticleInput $input, array $errors): ResponseInterface
    {
        return $this->html($request, $response, 'admin/article_form.html.twig', [
            'article' => $article,
            'input' => $input,
            'errors' => $errors,
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

    /**
     * @param array<string, string> $args
     */
    private function article(ServerRequestInterface $request, array $args): Article
    {
        return $this->articles->find((int) ($args['id'] ?? 0)) ?? throw new HttpNotFoundException($request);
    }

    private static function toEdit(ResponseInterface $response, int $id): ResponseInterface
    {
        return $response->withStatus(303)->withHeader('Location', '/admin/articles/' . $id);
    }
}
