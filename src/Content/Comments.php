<?php

declare(strict_types=1);

namespace Edict\Content;

use Edict\Clock;
use Edict\Database\Row;
use Edict\Mail\CommentAlert;
use Edict\Media\JobQueue;
use Edict\Site\Settings;

/**
 * Comments and their moderation. Readers post as their account, the sovereign can do
 * anything to anyone's comment, and everything a reader may not see stays out of the
 * query, not just out of the page.
 */
final readonly class Comments
{
    public const MAX_LENGTH = 5000;
    private const PAD = 12;
    private const BURST = 5;
    private const BURST_SECONDS = 600;
    private const DAILY = 60;
    private const FOREVER = '9999-12-31 00:00:00+00';

    public function __construct(private \PDO $pdo, private Clock $clock, private Settings $settings, private JobQueue $jobs) {}

    /**
     * Whether readers can comment on this article right now, and how.
     *
     * @return 'open'|'hold'|'closed'
     */
    public function status(Article $article, ?string $mode = null): string
    {
        $mode ??= $this->modeOf($article->id);
        if ($article->kind !== 'article' || $mode === 'closed') {
            return 'closed';
        }
        if ($mode === 'open' || $mode === 'hold') {
            return $mode;
        }
        $rules = $this->settings->commentRules();
        $days = $rules->autoCloseDays;
        if ($days > 0 && $article->publishedAt !== null && $article->publishedAt + $days * 86400 < $this->clock->now()) {
            return 'closed';
        }

        return $rules->defaultMode;
    }

    public function modeOf(int $articleId): string
    {
        $stmt = $this->pdo->prepare('SELECT comments_mode FROM article WHERE id = ?');
        $stmt->execute([$articleId]);
        $mode = $stmt->fetchColumn();

        return is_string($mode) ? $mode : 'site';
    }

    public function setMode(int $articleId, string $mode): bool
    {
        if (!in_array($mode, ['site', 'open', 'hold', 'closed'], true)) {
            return false;
        }
        $this->pdo->prepare('UPDATE article SET comments_mode = ? WHERE id = ?')->execute([$mode, $articleId]);

        return true;
    }

    /**
     * Posts a comment, or says why not.
     *
     * @return array{id: int, state: string}|string the new comment, or a message for the reader
     */
    public function post(Article $article, int $userId, bool $isAdmin, ?int $parentId, string $body): array|string
    {
        $body = trim(str_replace(["\r\n", "\r", "\0"], ["\n", "\n", ''], $body));
        if ($body === '') {
            return 'Please write something first.';
        }
        if (mb_strlen($body) > self::MAX_LENGTH) {
            return 'A comment can have up to ' . self::MAX_LENGTH . ' characters.';
        }
        $status = $this->status($article);
        if ($status === 'closed' && !$isAdmin) {
            return 'Comments on this article are closed.';
        }

        $author = $this->author($userId);
        if ($author === null) {
            return 'This account can’t comment.';
        }
        if ($author['banned'] && !$isAdmin) {
            return 'This account is banned from commenting.';
        }
        if (!$isAdmin && ($limit = $this->rateLimited($userId)) !== null) {
            return $limit;
        }

        $parent = null;
        if ($parentId !== null) {
            $stmt = $this->pdo->prepare("SELECT id, path, depth FROM comment WHERE id = ? AND article_id = ? AND state = 'visible'");
            $stmt->execute([$parentId, $article->id]);
            $parent = $stmt->fetch();
            if (!is_array($parent)) {
                return 'That comment can’t be answered any more.';
            }
        }

        $state = 'visible';
        if (!$isAdmin) {
            $rules = $this->settings->commentRules();
            if ($rules->matches($body)) {
                if ($rules->wordAction === 'reject') {
                    return 'Your comment contains words this site doesn’t accept.';
                }
                $state = 'held';
            } elseif ($status === 'hold' && !$author['trusted']) {
                $state = 'held';
            }
        }

        $this->pdo->beginTransaction();
        try {
            $insert = $this->pdo->prepare(
                'INSERT INTO comment (article_id, user_id, parent_id, depth, body, state, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, to_timestamp(?)) RETURNING id',
            );
            $insert->execute([
                $article->id, $userId, $parentId, is_array($parent) ? Row::int($parent, 'depth') + 1 : 0, $body, $state, $this->clock->now(),
            ]);
            $id = Row::int(['id' => $insert->fetchColumn()], 'id');
            $path = (is_array($parent) ? Row::str($parent, 'path') . '.' : '') . str_pad((string) $id, self::PAD, '0', STR_PAD_LEFT);
            $this->pdo->prepare('UPDATE comment SET path = ? WHERE id = ?')->execute([$path, $id]);
            // In the same transaction: no comment without its alert, and no alert without its comment.
            if (!$isAdmin) {
                $this->jobs->enqueue(CommentAlert::JOB, ['comment_id' => $id]);
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();

            throw $e;
        }

        return ['id' => $id, 'state' => $state];
    }

    /**
     * The thread as readers see it: visible comments, the reader's own held ones, and
     * "[removed]" only where replies still hang off it. The sovereign sees everything.
     *
     * @return list<CommentNode>
     */
    public function thread(int $articleId, ?int $viewerId, bool $isAdmin): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT c.id, c.parent_id, c.depth, c.body, c.state, c.pinned, c.user_id,
                    EXTRACT(EPOCH FROM c.created_at)::bigint AS created_at, c.edited_at IS NOT NULL AS edited,
                    coalesce(u.display_name, ?) AS author, coalesce(u.trusted, false) AS trusted,
                    coalesce(u.banned_until > to_timestamp(?), false) AS banned,
                    coalesce(root.pinned, false) AS root_pinned
             FROM comment c
             LEFT JOIN app_user u ON u.id = c.user_id
             LEFT JOIN comment root ON root.id = split_part(c.path, '.', 1)::bigint
             WHERE c.article_id = ?
             ORDER BY root_pinned DESC, c.path",
        );
        $stmt->execute(['[deleted user]', $this->clock->now(), $articleId]);

        /** @var array<int, array<mixed>> $rows in thread order: a parent always before its replies */
        $rows = [];
        foreach ($stmt->fetchAll() as $row) {
            if (is_array($row)) {
                $rows[Row::int($row, 'id')] = $row;
            }
        }

        // Replies first, so each comment knows whether anything still hangs off it.
        $kept = [];
        $hasReplies = [];
        foreach (array_reverse($rows, true) as $id => $row) {
            $state = Row::str($row, 'state');
            $mine = $viewerId !== null && Row::intOrNull($row, 'user_id') === $viewerId;
            $kept[$id] = $isAdmin || $state === 'visible' || ($state === 'held' && $mine) || ($state === 'removed' && isset($hasReplies[$id]));
            $parent = Row::intOrNull($row, 'parent_id');
            if ($kept[$id] && $parent !== null) {
                $hasReplies[$parent] = true;
            }
        }

        $children = [];
        $roots = [];
        foreach ($rows as $id => $row) {
            if (!$kept[$id]) {
                continue;
            }
            $parent = Row::intOrNull($row, 'parent_id');
            if ($parent !== null && ($kept[$parent] ?? false)) {
                $children[$parent][] = $id;
            } else {
                $roots[] = $id;
            }
        }

        $build = function (int $id) use (&$build, $rows, $children, $isAdmin): CommentNode {
            $row = $rows[$id];
            $parent = Row::intOrNull($row, 'parent_id');
            $hidden = Row::str($row, 'state') === 'removed' && !$isAdmin;

            return new CommentNode(
                $id,
                $parent,
                Row::int($row, 'depth'),
                $hidden ? '' : Row::str($row, 'body'),
                Row::str($row, 'state'),
                Row::bool($row, 'pinned'),
                Row::int($row, 'created_at'),
                Row::bool($row, 'edited'),
                $hidden ? null : Row::intOrNull($row, 'user_id'),
                $hidden ? '' : Row::str($row, 'author'),
                Row::bool($row, 'trusted'),
                Row::bool($row, 'banned'),
                $parent !== null && isset($rows[$parent]) ? Row::str($rows[$parent], 'author') : null,
                array_map($build, $children[$id] ?? []),
            );
        };

        return array_map($build, $roots);
    }

    public function count(int $articleId): int
    {
        $stmt = $this->pdo->prepare("SELECT count(*) FROM comment WHERE article_id = ? AND state = 'visible'");
        $stmt->execute([$articleId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @return array{articleId: int, slug: string, userId: int|null}|null
     */
    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT c.article_id, a.slug, c.user_id FROM comment c JOIN article a ON a.id = c.article_id WHERE c.id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return is_array($row) ? ['articleId' => Row::int($row, 'article_id'), 'slug' => Row::str($row, 'slug'), 'userId' => Row::intOrNull($row, 'user_id')] : null;
    }

    /**
     * Only this one, which stays as "[removed]" while replies hang off it, or the whole
     * subtree.
     */
    public function remove(int $id, bool $withReplies): void
    {
        if ($withReplies) {
            $this->pdo->prepare(
                "UPDATE comment SET state = 'removed'
                 WHERE article_id = (SELECT article_id FROM comment WHERE id = ?)
                   AND (path = (SELECT path FROM comment WHERE id = ?) OR path LIKE (SELECT path FROM comment WHERE id = ?) || '.%')",
            )->execute([$id, $id, $id]);

            return;
        }
        $this->pdo->prepare("UPDATE comment SET state = 'removed' WHERE id = ?")->execute([$id]);
    }

    public function edit(int $id, string $body): bool
    {
        $body = trim(str_replace(["\r\n", "\r", "\0"], ["\n", "\n", ''], $body));
        if ($body === '' || mb_strlen($body) > self::MAX_LENGTH) {
            return false;
        }
        $this->pdo->prepare('UPDATE comment SET body = ?, edited_at = to_timestamp(?) WHERE id = ?')->execute([$body, $this->clock->now(), $id]);

        return true;
    }

    public function pin(int $id, bool $pinned): void
    {
        $this->pdo->prepare('UPDATE comment SET pinned = ? WHERE id = ?')->execute([$pinned ? 'true' : 'false', $id]);
    }

    public function approve(int $id): void
    {
        $this->pdo->prepare("UPDATE comment SET state = 'visible' WHERE id = ? AND state = 'held'")->execute([$id]);
    }

    public function trust(int $userId, bool $trusted): void
    {
        $this->pdo->prepare('UPDATE app_user SET trusted = ? WHERE id = ? AND NOT is_admin')->execute([$trusted ? 'true' : 'false', $userId]);
    }

    /**
     * @param int|null $days null for good
     */
    public function ban(int $userId, ?int $days): void
    {
        $until = $days === null ? self::FOREVER : gmdate('Y-m-d H:i:sP', $this->clock->now() + $days * 86400);
        $this->pdo->prepare('UPDATE app_user SET banned_until = ?::timestamptz WHERE id = ? AND NOT is_admin')->execute([$until, $userId]);
        // Their sessions end, so nothing they had open keeps working.
        $this->pdo->prepare('DELETE FROM session WHERE user_id = ?')->execute([$userId]);
    }

    public function unban(int $userId): void
    {
        $this->pdo->prepare('UPDATE app_user SET banned_until = NULL WHERE id = ?')->execute([$userId]);
    }

    /**
     * For the queue: what waits, newest first, and the latest of the rest.
     *
     * @return list<array{id: int, body: string, state: string, createdAt: int, author: string, authorId: int|null, trusted: bool, slug: string, title: string}>
     */
    public function queue(string $state, int $limit): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT c.id, c.body, c.state, EXTRACT(EPOCH FROM c.created_at)::bigint AS created_at, c.user_id,
                    coalesce(u.display_name, '[deleted user]') AS author, coalesce(u.trusted, false) AS trusted, a.slug, a.title
             FROM comment c JOIN article a ON a.id = c.article_id LEFT JOIN app_user u ON u.id = c.user_id
             WHERE c.state = ? ORDER BY c.created_at DESC, c.id DESC LIMIT ?",
        );
        $stmt->execute([$state, $limit]);
        $rows = [];
        foreach ($stmt->fetchAll() as $row) {
            if (is_array($row)) {
                $rows[] = [
                    'id' => Row::int($row, 'id'),
                    'body' => Row::str($row, 'body'),
                    'state' => Row::str($row, 'state'),
                    'createdAt' => Row::int($row, 'created_at'),
                    'author' => Row::str($row, 'author'),
                    'authorId' => Row::intOrNull($row, 'user_id'),
                    'trusted' => Row::bool($row, 'trusted'),
                    'slug' => Row::str($row, 'slug'),
                    'title' => Row::str($row, 'title'),
                ];
            }
        }

        return $rows;
    }

    public function heldCount(): int
    {
        $stmt = $this->pdo->query("SELECT count(*) FROM comment WHERE state = 'held'");

        return $stmt === false ? 0 : (int) $stmt->fetchColumn();
    }

    /**
     * @return list<array{id: int, name: string, until: int}>
     */
    public function banned(): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, display_name, EXTRACT(EPOCH FROM banned_until)::bigint AS until FROM app_user
             WHERE banned_until > to_timestamp(?) ORDER BY banned_until',
        );
        $stmt->execute([$this->clock->now()]);
        $rows = [];
        foreach ($stmt->fetchAll() as $row) {
            if (is_array($row)) {
                $rows[] = ['id' => Row::int($row, 'id'), 'name' => Row::str($row, 'display_name'), 'until' => Row::int($row, 'until')];
            }
        }

        return $rows;
    }

    /**
     * @return array{trusted: bool, banned: bool}|null
     */
    private function author(int $userId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT trusted, coalesce(banned_until > to_timestamp(?), false) AS banned FROM app_user WHERE id = ? AND deleted_at IS NULL',
        );
        $stmt->execute([$this->clock->now(), $userId]);
        $row = $stmt->fetch();

        return is_array($row) ? ['trusted' => Row::bool($row, 'trusted'), 'banned' => Row::bool($row, 'banned')] : null;
    }

    private function rateLimited(int $userId): ?string
    {
        $now = $this->clock->now();
        $stmt = $this->pdo->prepare(
            'SELECT count(*) FILTER (WHERE created_at > to_timestamp(?)) AS burst, count(*) AS daily
             FROM comment WHERE user_id = ? AND created_at > to_timestamp(?)',
        );
        $stmt->execute([$now - self::BURST_SECONDS, $userId, $now - 86400]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            return null;
        }
        if (Row::int($row, 'burst') >= self::BURST) {
            return 'That was a lot of comments in a short time. Please wait a few minutes.';
        }

        return Row::int($row, 'daily') >= self::DAILY ? 'That’s the most comments one account can post in a day. Please come back tomorrow.' : null;
    }
}
