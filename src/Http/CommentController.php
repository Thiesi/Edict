<?php

declare(strict_types=1);

namespace Edict\Http;

use Edict\Auth\Accounts;
use Edict\Auth\Session;
use Edict\Content\Articles;
use Edict\Content\Comments;
use Edict\Http\Middleware\CsrfMiddleware;
use Edict\Http\Middleware\SessionMiddleware;
use Edict\Mail\Mailer;
use Edict\Mail\SecurityNotice;
use Edict\Site\Settings;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;
use Twig\Environment;

/**
 * Posting comments, and every moderation action. Each action is a plain form post that
 * comes back to where it was pressed: the article, or the queue.
 */
final readonly class CommentController
{
    private const BAN_DAYS = ['1' => 1, '7' => 7, '30' => 30, '365' => 365, 'forever' => null];

    public function __construct(
        private Comments $comments,
        private Articles $articles,
        private PublicController $public,
        private Settings $settings,
        private Environment $twig,
        private Mailer $mailer,
        private Accounts $accounts,
        private SecurityNotice $security,
    ) {}

    /**
     * @param array<string, string> $args
     */
    public function post(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $article = $this->articles->find((int) ($args['id'] ?? 0));
        $article = $article === null ? null : $this->articles->findPublic($article->slug);
        if ($article === null) {
            throw new HttpNotFoundException($request);
        }
        $session = $request->getAttribute(SessionMiddleware::ATTRIBUTE);
        if (!$session instanceof Session) {
            return self::redirect($response, '/login?next=' . rawurlencode('/' . $article->slug . '#comments'));
        }

        $body = $request->getParsedBody();
        $text = is_array($body) && is_string($body['body'] ?? null) ? $body['body'] : '';
        $parent = is_array($body) && is_string($body['parent'] ?? null) && ctype_digit($body['parent']) ? (int) $body['parent'] : null;

        $result = $this->comments->post($article, $session->userId, $session->isAdmin, $parent, $text);
        if (is_string($result)) {
            return $this->public->showArticle($request, $response->withStatus(422), $article, ['error' => $result, 'text' => $text, 'parent' => $parent]);
        }

        return self::redirect($response, '/' . $article->slug . '?posted=' . $result['id'] . '#comment-' . $result['id']);
    }

    /**
     * A reader deletes their own comment, and its text is erased (Comments::erase):
     * replies stay, under "[removed]". Anyone else's comment, or one that doesn't exist,
     * is not found, so the answer doesn't tell which. Behind RequireLogin.
     *
     * @param array<string, string> $args
     */
    public function deleteOwn(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $session = $request->getAttribute(SessionMiddleware::ATTRIBUTE);
        $id = (int) ($args['id'] ?? 0);
        $comment = $this->comments->find($id);
        if (!$session instanceof Session || $comment === null || $comment['userId'] !== $session->userId) {
            throw new HttpNotFoundException($request);
        }
        $this->comments->erase($id);

        return self::redirect($response, '/' . $comment['slug'] . '?done=comment-deleted#comments');
    }

    /**
     * delete, delete-thread, pin, unpin, approve, edit; and for the author: trust, untrust,
     * ban, delete-account.
     *
     * @param array<string, string> $args
     */
    public function moderate(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = (int) ($args['id'] ?? 0);
        $comment = $this->comments->find($id);
        if ($comment === null) {
            throw new HttpNotFoundException($request);
        }
        $body = $request->getParsedBody();
        $form = is_array($body) ? $body : [];
        $action = $args['action'] ?? '';
        $anchor = '#comment-' . $id;
        if ($action === 'delete-account') {
            return $this->deleteAccount($request, $response, $comment, $form);
        }

        match ($action) {
            'delete' => $this->comments->remove($id, false),
            'delete-thread' => $this->comments->remove($id, true),
            'pin' => $this->comments->pin($id, true),
            'unpin' => $this->comments->pin($id, false),
            'approve' => $this->comments->approve($id),
            'edit' => $this->comments->edit($id, is_string($form['body'] ?? null) ? $form['body'] : ''),
            'trust', 'untrust', 'ban' => $comment['userId'] === null ? null : $this->author($action, $comment['userId'], $form),
            default => throw new HttpNotFoundException($request),
        };

        $back = is_string($form['back'] ?? null) && $form['back'] === 'queue' ? '/admin/comments' : '/' . $comment['slug'] . $anchor;
        if (in_array($action, ['delete', 'delete-thread'], true) && $back !== '/admin/comments') {
            $back = '/' . $comment['slug'] . '#comments';
        }

        return self::redirect($response, $back);
    }

    /**
     * @param array<string, string> $args
     */
    public function mode(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $article = $this->articles->find((int) ($args['id'] ?? 0)) ?? throw new HttpNotFoundException($request);
        $body = $request->getParsedBody();
        $mode = is_array($body) && is_string($body['mode'] ?? null) ? $body['mode'] : '';
        $this->comments->setMode($article->id, $mode);

        return self::redirect($response, '/' . $article->slug . '#comments');
    }

    /**
     * @param array<string, string> $args
     */
    public function unban(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $this->comments->unban((int) ($args['id'] ?? 0));

        return self::redirect($response, '/admin/comments');
    }

    public function queue(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->queuePage($request, $response, [], null);
    }

    public function saveRules(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = $request->getParsedBody();
        $form = is_array($body) ? $body : [];
        $errors = $this->settings->saveCommentRules($form);
        if ($errors === []) {
            return self::redirect($response, '/admin/comments?saved=1');
        }

        return $this->queuePage($request, $response->withStatus(422), $errors, $form);
    }

    /**
     * @param array<string, string> $errors
     * @param array<mixed>|null $form
     */
    private function queuePage(ServerRequestInterface $request, ResponseInterface $response, array $errors, ?array $form, ?string $alert = null): ResponseInterface
    {
        $rules = $this->settings->commentRules();
        $session = $request->getAttribute(SessionMiddleware::ATTRIBUTE);
        $response->getBody()->write($this->twig->render('admin/comments.html.twig', [
            'held' => $this->comments->queue('held', 200),
            'recent' => $this->comments->queue('visible', 30),
            'banned' => $this->comments->banned(),
            'rules' => $form ?? [
                'comment_default' => $rules->defaultMode,
                'comment_auto_close_days' => (string) $rules->autoCloseDays,
                'comment_words' => implode("\n", $rules->words),
                'comment_word_action' => $rules->wordAction,
                'comment_alerts' => $rules->alerts ? '1' : '',
            ],
            'alert_to' => $this->mailer->isConfigured() ? $this->mailer->recipient() : null,
            'errors' => $errors,
            'saved' => ($request->getQueryParams()['saved'] ?? null) === '1',
            'account_deleted' => $alert === null && ($request->getQueryParams()['done'] ?? null) === 'account-deleted',
            'alert' => $alert,
            'csrf_field' => CsrfMiddleware::FIELD,
            'csrf_token' => $session instanceof Session ? $session->csrfToken : '',
        ]));

        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    /**
     * @param array<mixed> $form
     */
    private function author(string $action, int $userId, array $form): void
    {
        if ($action === 'ban') {
            $days = is_string($form['days'] ?? null) && array_key_exists($form['days'], self::BAN_DAYS) ? self::BAN_DAYS[$form['days']] : 7;
            $this->comments->ban($userId, $days);

            return;
        }
        $this->comments->trust($userId, $action === 'trust');
    }

    /**
     * Exactly what a reader's own deletion does: logins, sessions and likes go, the
     * comments stay under "[deleted user]". Never the admin account. Logged, not mailed:
     * the admin did it on purpose.
     *
     * @param array{articleId: int, slug: string, userId: int|null} $comment
     * @param array<mixed> $form
     */
    private function deleteAccount(ServerRequestInterface $request, ResponseInterface $response, array $comment, array $form): ResponseInterface
    {
        $toQueue = ($form['back'] ?? null) === 'queue';
        $userId = $comment['userId'];
        $error = null;
        if ($userId === null) {
            throw new HttpNotFoundException($request);
        }
        if (($form['confirm'] ?? null) !== 'yes') {
            $error = 'Please tick “Delete permanently” to delete the account.';
        } else {
            try {
                $this->accounts->delete($userId);
                $this->security->log("The admin deleted reader account $userId");
            } catch (\DomainException) {
                $error = 'The admin account can’t be deleted.';
            }
        }

        if ($error === null) {
            return self::redirect($response, $toQueue ? '/admin/comments?done=account-deleted' : '/' . $comment['slug'] . '?done=account-deleted#comments');
        }
        if ($toQueue) {
            return $this->queuePage($request, $response->withStatus(422), [], null, $error);
        }
        $article = $this->articles->find($comment['articleId']) ?? throw new HttpNotFoundException($request);

        return $this->public->showArticle($request, $response->withStatus(422), $article, ['alert' => $error]);
    }

    private static function redirect(ResponseInterface $response, string $to): ResponseInterface
    {
        return $response->withStatus(303)->withHeader('Location', $to);
    }
}
