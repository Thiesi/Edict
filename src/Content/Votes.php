<?php

declare(strict_types=1);

namespace Edict\Content;

use Edict\Clock;
use Edict\Database\Row;

/**
 * Likes: one per article and account, from readers who are neither banned nor
 * deleted, on articles readers can see. What the page shows is the real number plus
 * the sovereign's adjustment, never below zero.
 */
final readonly class Votes
{
    public const MAX_ADJUSTMENT = 1_000_000_000;

    public function __construct(private \PDO $pdo, private Clock $clock) {}

    /**
     * @return array{real: int, adjustment: int, shown: int, mine: bool}
     */
    public function tally(int $articleId, ?int $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT (SELECT count(*) FROM vote WHERE article_id = a.id) AS real, a.vote_adjustment,
                    EXISTS (SELECT 1 FROM vote WHERE article_id = a.id AND user_id = ?) AS mine
             FROM article a WHERE a.id = ?',
        );
        $stmt->execute([$userId ?? 0, $articleId]);
        $row = $stmt->fetch();
        $real = is_array($row) ? Row::int($row, 'real') : 0;
        $adjustment = is_array($row) ? Row::int($row, 'vote_adjustment') : 0;

        return [
            'real' => $real,
            'adjustment' => $adjustment,
            'shown' => max(0, $real + $adjustment),
            'mine' => is_array($row) && Row::bool($row, 'mine'),
        ];
    }

    /**
     * @return bool false if this reader may not vote, or the article isn't public
     */
    public function like(int $articleId, int $userId): bool
    {
        $now = $this->clock->now();
        $stmt = $this->pdo->prepare(
            "INSERT INTO vote (article_id, user_id, created_at)
             SELECT a.id, u.id, to_timestamp(?) FROM article a, app_user u
             WHERE a.id = ? AND a.status = 'published' AND a.deleted_at IS NULL AND a.published_at <= to_timestamp(?)
               AND u.id = ? AND u.deleted_at IS NULL AND (u.banned_until IS NULL OR u.banned_until <= to_timestamp(?))
             ON CONFLICT DO NOTHING RETURNING 1",
        );
        $stmt->execute([$now, $articleId, $now, $userId, $now]);

        return $stmt->fetchColumn() !== false || $this->tally($articleId, $userId)['mine'];
    }

    public function unlike(int $articleId, int $userId): void
    {
        $this->pdo->prepare('DELETE FROM vote WHERE article_id = ? AND user_id = ?')->execute([$articleId, $userId]);
    }

    public function setAdjustment(int $articleId, int $adjustment): void
    {
        $this->pdo->prepare('UPDATE article SET vote_adjustment = ? WHERE id = ?')
            ->execute([max(-self::MAX_ADJUSTMENT, min(self::MAX_ADJUSTMENT, $adjustment)), $articleId]);
    }

    public function addToAdjustment(int $articleId, int $amount): void
    {
        $this->pdo->prepare('UPDATE article SET vote_adjustment = greatest(?, least(?, vote_adjustment::bigint + ?)) WHERE id = ?')
            ->execute([-self::MAX_ADJUSTMENT, self::MAX_ADJUSTMENT, $amount, $articleId]);
    }

    public function isBanned(int $userId): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM app_user WHERE id = ? AND banned_until > to_timestamp(?)');
        $stmt->execute([$userId, $this->clock->now()]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Real likes and adjustment per article, for the admin list.
     *
     * @param list<int> $articleIds
     *
     * @return array<int, array{real: int, adjustment: int, shown: int}>
     */
    public function counts(array $articleIds): array
    {
        if ($articleIds === []) {
            return [];
        }
        $stmt = $this->pdo->prepare(
            'SELECT a.id, a.vote_adjustment, (SELECT count(*) FROM vote WHERE article_id = a.id) AS real
             FROM article a WHERE a.id = ANY(?::bigint[])',
        );
        $stmt->execute(['{' . implode(',', $articleIds) . '}']);
        $counts = [];
        foreach ($stmt->fetchAll() as $row) {
            if (is_array($row)) {
                $real = Row::int($row, 'real');
                $adjustment = Row::int($row, 'vote_adjustment');
                $counts[Row::int($row, 'id')] = ['real' => $real, 'adjustment' => $adjustment, 'shown' => max(0, $real + $adjustment)];
            }
        }

        return $counts;
    }
}
