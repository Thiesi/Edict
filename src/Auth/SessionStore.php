<?php

declare(strict_types=1);

namespace Edict\Auth;

use Edict\Clock;
use Edict\Database\Row;

/**
 * Database-backed sessions. The cookie holds a random token; only its SHA-256
 * is stored, so a leaked DB or dump doesn't contain usable sessions.
 */
final readonly class SessionStore
{
    public const ADMIN_TTL = 12 * 3600;
    public const READER_TTL = 30 * 86400;
    private const TOUCH_INTERVAL = 300;

    public function __construct(private \PDO $pdo, private Clock $clock) {}

    /**
     * @return string the cookie token for the new session
     */
    public function create(int $userId, int $ttl): string
    {
        $now = $this->clock->now();

        // Expired rows are removed here, so the table can't grow without bound.
        $this->pdo->prepare('DELETE FROM session WHERE expires_at <= to_timestamp(?)')->execute([$now]);

        $token = self::base64Url(random_bytes(32));
        $this->pdo->prepare(
            'INSERT INTO session (token_hash, user_id, csrf_token, created_at, last_seen_at, expires_at)
             VALUES (?, ?, ?, to_timestamp(?), to_timestamp(?), to_timestamp(?))',
        )->execute([self::hashToken($token), $userId, self::base64Url(random_bytes(32)), $now, $now, $now + $ttl]);

        return $token;
    }

    public function load(#[\SensitiveParameter] string $token): ?Session
    {
        $now = $this->clock->now();
        $hash = self::hashToken($token);
        $stmt = $this->pdo->prepare(
            'SELECT s.user_id, s.csrf_token, u.display_name, u.is_admin,
                    EXTRACT(EPOCH FROM s.last_seen_at)::bigint AS last_seen
             FROM session s JOIN app_user u ON u.id = s.user_id
             WHERE s.token_hash = ? AND s.expires_at > to_timestamp(?) AND u.deleted_at IS NULL',
        );
        $stmt->execute([$hash, $now]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            return null;
        }

        if ($now - Row::int($row, 'last_seen') >= self::TOUCH_INTERVAL) {
            $this->pdo->prepare('UPDATE session SET last_seen_at = to_timestamp(?) WHERE token_hash = ?')->execute([$now, $hash]);
        }

        return new Session(
            $hash,
            Row::int($row, 'user_id'),
            Row::str($row, 'display_name'),
            Row::bool($row, 'is_admin'),
            Row::str($row, 'csrf_token'),
        );
    }

    public function destroy(Session $session): void
    {
        $this->pdo->prepare('DELETE FROM session WHERE token_hash = ?')->execute([$session->tokenHash]);
    }

    /**
     * @return int number of sessions ended
     */
    public function destroyAllForUser(int $userId): int
    {
        $stmt = $this->pdo->prepare('DELETE FROM session WHERE user_id = ?');
        $stmt->execute([$userId]);

        return $stmt->rowCount();
    }

    private static function hashToken(#[\SensitiveParameter] string $token): string
    {
        return hash('sha256', $token);
    }

    private static function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
