<?php

declare(strict_types=1);

namespace Edict\Http;

use Edict\Auth\Session;
use Edict\Config;
use Edict\Content\Article;
use Edict\Content\ArticleInput;
use Edict\Content\Articles;
use Edict\Content\Body;
use Edict\Content\SlugTaken;
use Edict\Content\StaleDraft;
use Edict\Http\Middleware\CsrfMiddleware;
use Edict\Http\Middleware\SessionMiddleware;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;
use Twig\Environment;

/**
 * The article and page lists and the editor. All routes sit behind RequireAdmin.
 */
final readonly class ArticleAdminController
{
    public function __construct(private Environment $twig, private Articles $articles, private Config $config) {}

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $query = $request->getQueryParams();
        $trash = ($query['trash'] ?? null) === '1';
        $kind = ($query['kind'] ?? null) === 'page' ? 'page' : 'article';
        if ($trash) {
            $this->articles->purgeTrash();
        }

        return $this->html($request, $response, 'admin/articles.html.twig', [
            'articles' => $this->articles->adminList($kind, $trash),
            'kind' => $kind,
            'trash' => $trash,
            'trash_days' => Articles::TRASH_DAYS,
        ]);
    }

    /**
     * Creates the row straight away, so autosave has somewhere to write from the
     * first keystroke.
     */
    public function create(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = $request->getParsedBody();
        $kind = is_array($body) && ($body['kind'] ?? null) === 'page' ? 'page' : 'article';

        try {
            $id = $this->articles->createEmpty($kind);
        } catch (SlugTaken) {
            // A double click: both requests picked the same placeholder address.
            $id = $this->articles->createEmpty($kind);
        }

        return $response->withStatus(303)->withHeader('Location', '/admin/articles/' . $id);
    }

    /**
     * @param array<string, string> $args
     */
    public function edit(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $article = $this->article($request, $args);
        $input = $article->input();
        $session = $request->getAttribute(SessionMiddleware::ATTRIBUTE);

        return $this->html($request, $response, 'admin/editor.html.twig', [
            'article' => $article,
            'input' => $input,
            'asset_version' => $this->config->release(),
            'state' => [
                'id' => $article->id,
                'version' => $article->draftVersion,
                'body' => Body::decode($input->body),
                'csrf' => $session instanceof Session ? $session->csrfToken : '',
            ],
        ]);
    }

    /**
     * @param array<string, string> $args
     */
    public function autosave(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $article = $this->article($request, $args);
        $payload = $request->getParsedBody();
        $draft = is_array($payload) ? ($payload['draft'] ?? null) : null;
        $version = is_array($payload) ? ($payload['version'] ?? null) : null;
        $writer = is_array($payload) ? ($payload['writer'] ?? null) : null;

        // A malformed request must never replace a real draft with an empty one.
        if (!is_array($draft) || !is_int($version) || !is_string($writer) || preg_match('/^[a-zA-Z0-9-]{1,64}$/D', $writer) !== 1) {
            return self::json($response->withStatus(400), ['error' => 'The save was malformed.']);
        }
        if ($article->deletedAt !== null) {
            return self::json($response->withStatus(409), ['error' => 'This is in the trash.']);
        }

        // Refused rather than cut down: saving must never drop text without saying so.
        if (Body::isTooDeep($draft['body'] ?? null)) {
            return self::json($response->withStatus(422), ['error' => 'The lists or quotes are nested too deeply.']);
        }
        if (Body::hasOversizedGallery($draft['body'] ?? null)) {
            return self::json($response->withStatus(422), ['error' => 'A gallery can have up to 100 images.']);
        }
        $json = ArticleInput::fromArray($draft)->toJson();
        if (strlen($json) > ArticleInput::MAX_DRAFT_BYTES) {
            return self::json($response->withStatus(413), ['error' => 'The text is too long.']);
        }

        $saved = $this->articles->saveDraft($article->id, $version, $writer, $json);
        if ($saved === null) {
            return self::json($response->withStatus(409), ['error' => 'This changed in another tab.']);
        }

        return self::json($response, ['version' => $saved]);
    }

    /**
     * Makes the stored draft live. The editor saves first, and sends the version it
     * saved so that newer text from elsewhere can't go live unseen.
     *
     * @param array<string, string> $args
     */
    public function publish(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $article = $this->article($request, $args);
        $payload = $request->getParsedBody();
        if (!is_array($payload) || ($payload['version'] ?? null) !== $article->draftVersion || $article->deletedAt !== null) {
            return self::json($response->withStatus(409), ['error' => 'This changed in another tab.']);
        }

        $input = $article->input();
        $errors = $input->errors();
        if ($errors === []) {
            try {
                $this->articles->publish($article, $input);

                return self::json($response, ['redirect' => '/admin/articles/' . $article->id]);
            } catch (SlugTaken) {
                $errors['slug'] = 'That address is already in use.';
            } catch (StaleDraft) {
                return self::json($response->withStatus(409), ['error' => 'This changed in another tab.']);
            }
        }

        return self::json($response->withStatus(422), ['errors' => $errors]);
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
        $article = $this->article($request, $args);
        $this->articles->trash($article->id);

        return $response->withStatus(303)->withHeader('Location', '/admin/articles?kind=' . $article->kind);
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

        return $response->withStatus(303)->withHeader('Location', '/admin/articles?trash=1&kind=' . $article->kind);
    }

    /**
     * The draft in the public template, so it looks exactly as readers will see it.
     *
     * @param array<string, string> $args
     */
    public function preview(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        return $this->html($request, $response, 'public/article.html.twig', [
            'article' => $this->article($request, $args)->asDraft(),
            'preview' => true,
            'signed_in' => true,
        ])->withHeader('X-Robots-Tag', 'noindex');
    }

    /**
     * After signing in again in another tab, an open editor needs the new session's
     * token before it can save the text it is holding.
     */
    public function csrf(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $session = $request->getAttribute(SessionMiddleware::ATTRIBUTE);

        return self::json($response, ['csrf' => $session instanceof Session ? $session->csrfToken : '']);
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

    private static function json(ResponseInterface $response, mixed $data): ResponseInterface
    {
        $response->getBody()->write(json_encode($data, JSON_THROW_ON_ERROR));

        return $response->withHeader('Content-Type', 'application/json');
    }

    private static function toEdit(ResponseInterface $response, int $id): ResponseInterface
    {
        return $response->withStatus(303)->withHeader('Location', '/admin/articles/' . $id);
    }
}
