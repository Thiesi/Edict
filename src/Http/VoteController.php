<?php

declare(strict_types=1);

namespace Edict\Http;

use Edict\Auth\Session;
use Edict\Content\Articles;
use Edict\Content\Votes;
use Edict\Http\Middleware\SessionMiddleware;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;

/**
 * Liking, and the sovereign's adjustment. A plain form post works without JavaScript
 * and comes back to the article; the site's script asks for JSON instead.
 */
final readonly class VoteController
{
    public function __construct(private Votes $votes, private Articles $articles) {}

    /**
     * @param array<string, string> $args
     */
    public function like(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $article = $this->articles->find((int) ($args['id'] ?? 0));
        if ($article === null || $this->articles->findPublic($article->slug) === null) {
            throw new HttpNotFoundException($request);
        }
        $session = $request->getAttribute(SessionMiddleware::ATTRIBUTE);
        if (!$session instanceof Session) {
            return $response->withStatus(303)->withHeader('Location', '/login?next=' . rawurlencode('/' . $article->slug));
        }

        $body = $request->getParsedBody();
        $wanted = is_array($body) ? ($body['like'] ?? null) : null;
        $refused = false;
        if ($wanted === '0' || $wanted === false || $wanted === 0) {
            $this->votes->unlike($article->id, $session->userId);
        } else {
            $refused = !$this->votes->like($article->id, $session->userId);
        }

        return $this->answer($request, $response, $article->id, $session->userId, '/' . $article->slug . '#likes', $refused ? 403 : 200);
    }

    /**
     * Sets the adjustment, or adds to it: "+100".
     *
     * @param array<string, string> $args
     */
    public function adjust(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $article = $this->articles->find((int) ($args['id'] ?? 0));
        if ($article === null) {
            throw new HttpNotFoundException($request);
        }
        $body = $request->getParsedBody();
        $set = is_array($body) ? self::integer($body['adjustment'] ?? null) : null;
        $add = is_array($body) ? self::integer($body['add'] ?? null) : null;
        if ($set === null && $add === null) {
            return self::json($response->withStatus(400), ['error' => 'Give a number.']);
        }
        if ($set !== null) {
            $this->votes->setAdjustment($article->id, $set);
        } elseif ($add !== null) {
            $this->votes->addToAdjustment($article->id, $add);
        }
        $session = $request->getAttribute(SessionMiddleware::ATTRIBUTE);
        $public = $this->articles->findPublic($article->slug) !== null;

        return $this->answer(
            $request,
            $response,
            $article->id,
            $session instanceof Session ? $session->userId : null,
            $public ? '/' . $article->slug . '#likes' : '/admin/articles',
        );
    }

    private function answer(ServerRequestInterface $request, ResponseInterface $response, int $articleId, ?int $userId, string $back, int $status = 200): ResponseInterface
    {
        if (str_contains($request->getHeaderLine('Accept'), 'application/json')) {
            return self::json($response->withStatus($status), $this->votes->tally($articleId, $userId));
        }

        return $response->withStatus(303)->withHeader('Location', $back);
    }

    private static function integer(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        return is_string($value) && preg_match('~^[+-]?[0-9]{1,10}$~D', trim($value)) === 1 ? (int) trim($value) : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function json(ResponseInterface $response, array $data): ResponseInterface
    {
        $response->getBody()->write(json_encode($data, JSON_THROW_ON_ERROR));

        return $response->withHeader('Content-Type', 'application/json')->withHeader('Cache-Control', 'no-store');
    }
}
