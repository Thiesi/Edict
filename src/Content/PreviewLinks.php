<?php

declare(strict_types=1);

namespace Edict\Content;

use Edict\Clock;
use Edict\Database\Row;

/**
 * Private preview links: one per piece, showing its current draft to anyone who has
 * the link, until it expires or is stopped. Publishing, scheduling and trashing end
 * it too (see Articles), and deleting the piece takes the row along.
 *
 * The token is 32 random bytes and only its SHA-256 is stored, as for sessions. The
 * lookup is by that hash: whatever an index comparison's timing gives away is about
 * the hash, which says nothing about a token that would match it.
 */
final readonly class PreviewLinks
{
    public const DAYS = 7;
    /** base64url of 32 bytes, without padding. */
    public const TOKEN_PATTERN = '[A-Za-z0-9_-]{43}';

    public function __construct(private \PDO $pdo, private Clock $clock) {}

    /**
     * A new link for the piece. An earlier one stops working.
     *
     * @return array{token: string, expiresAt: int}
     */
    public function create(int $articleId): array
    {
        $now = $this->clock->now();
        $expiresAt = $now + self::DAYS * 86400;
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        // Expired rows are useless; removing them here keeps the table to live links.
        $this->pdo->prepare('DELETE FROM preview_link WHERE expires_at <= to_timestamp(?)')->execute([$now]);
        $this->pdo->prepare(
            'INSERT INTO preview_link (article_id, token_hash, created_at, expires_at) VALUES (?, ?, to_timestamp(?), to_timestamp(?))
             ON CONFLICT (article_id) DO UPDATE SET token_hash = EXCLUDED.token_hash, created_at = EXCLUDED.created_at, expires_at = EXCLUDED.expires_at',
        )->execute([$articleId, self::hash($token), $now, $expiresAt]);

        return ['token' => $token, 'expiresAt' => $expiresAt];
    }

    /**
     * @return int|null the piece the token shows, or null for a token that is unknown,
     *                  replaced, stopped or expired, or whose piece is in the trash. The
     *                  caller can't tell which, and neither can the reader.
     */
    public function articleFor(#[\SensitiveParameter] string $token): ?int
    {
        $stmt = $this->pdo->prepare(
            'SELECT p.article_id FROM preview_link p JOIN article a ON a.id = p.article_id
             WHERE p.token_hash = ? AND p.expires_at > to_timestamp(?) AND a.deleted_at IS NULL',
        );
        $stmt->execute([self::hash($token), $this->clock->now()]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : Row::int(['id' => $id], 'id');
    }

    /**
     * @return int|null when the piece's link expires, if it has one that still works
     */
    public function expiresAt(int $articleId): ?int
    {
        $stmt = $this->pdo->prepare(
            'SELECT EXTRACT(EPOCH FROM expires_at)::bigint FROM preview_link WHERE article_id = ? AND expires_at > to_timestamp(?)',
        );
        $stmt->execute([$articleId, $this->clock->now()]);
        $expiresAt = $stmt->fetchColumn();

        return $expiresAt === false ? null : Row::int(['e' => $expiresAt], 'e');
    }

    public function stop(int $articleId): void
    {
        $this->pdo->prepare('DELETE FROM preview_link WHERE article_id = ?')->execute([$articleId]);
    }

    private static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
