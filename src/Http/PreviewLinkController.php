<?php

declare(strict_types=1);

namespace Edict\Http;

use Edict\Auth\LoginThrottle;
use Edict\Auth\Session;
use Edict\Config;
use Edict\Content\ArticleDate;
use Edict\Content\Articles;
use Edict\Content\PreviewLinks;
use Edict\Http\Middleware\SessionMiddleware;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;
use Twig\Environment;

/**
 * Private preview links (#85). The editor makes and stops them; anyone with a link
 * reads the current draft, without an account. Making and stopping sit behind
 * RequireAdmin; the page itself is public.
 */
final readonly class PreviewLinkController
{
    public function __construct(
        private Environment $twig,
        private Articles $articles,
        private PreviewLinks $links,
        private LoginThrottle $throttle,
        private Config $config,
    ) {}

    /**
     * The draft, as the admin preview shows it, without likes or comments. A link that
     * doesn't work, for whatever reason, is the site's ordinary 404, and counts
     * against the client's address.
     *
     * @param array<string, string> $args
     */
    public function show(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $ip = self::ip($request);
        if ($this->throttle->isPreviewBlocked($ip)) {
            throw new HttpNotFoundException($request);
        }
        $id = $this->links->articleFor($args['token'] ?? '');
        $article = $id === null ? null : $this->articles->find($id);
        if ($article === null) {
            $this->throttle->recordPreviewFailure($ip);

            throw new HttpNotFoundException($request);
        }

        $response->getBody()->write($this->twig->render('public/article.html.twig', [
            'article' => $article->asDraft(),
            'preview' => true,
            'shared_preview' => true,
            'signed_in' => $request->getAttribute(SessionMiddleware::ATTRIBUTE) instanceof Session,
        ]));

        // The link is the key: no search engine, no cache and no referrer may keep it.
        return $response
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withHeader('X-Robots-Tag', 'noindex')
            ->withHeader('Referrer-Policy', 'no-referrer')
            ->withHeader('Cache-Control', 'private, no-store');
    }

    /**
     * A new link; the piece's earlier one stops working. The link is shown only now:
     * the database keeps nothing it could be rebuilt from.
     *
     * @param array<string, string> $args
     */
    public function create(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $article = $this->articles->find((int) ($args['id'] ?? 0)) ?? throw new HttpNotFoundException($request);
        if ($article->deletedAt !== null) {
            return self::json($response->withStatus(409), ['error' => 'This is in the trash, so it can’t be shared.']);
        }
        ['token' => $token, 'expiresAt' => $expiresAt] = $this->links->create($article->id);

        return self::json($response, [
            'url' => $this->config->appUrl . '/preview/' . $token,
            'expires' => ArticleDate::adminDateTime($expiresAt),
            'expiresIso' => ArticleDate::iso($expiresAt),
        ]);
    }

    /**
     * @param array<string, string> $args
     */
    public function stop(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $article = $this->articles->find((int) ($args['id'] ?? 0)) ?? throw new HttpNotFoundException($request);
        $this->links->stop($article->id);

        return self::json($response, ['stopped' => true]);
    }

    private static function ip(ServerRequestInterface $request): string
    {
        $remote = $request->getServerParams()['REMOTE_ADDR'] ?? null;

        return is_string($remote) ? $remote : 'unknown';
    }

    private static function json(ResponseInterface $response, mixed $data): ResponseInterface
    {
        $response->getBody()->write(json_encode($data, JSON_THROW_ON_ERROR));

        return $response->withHeader('Content-Type', 'application/json');
    }
}
