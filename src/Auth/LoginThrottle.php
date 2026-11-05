<?php

declare(strict_types=1);

namespace Edict\Auth;

use Edict\Clock;
use Edict\Config;

/**
 * Throttles failed logins per client IP and per username. Buckets are keyed
 * HMACs, so the table contains no IP addresses or attempted usernames.
 */
final readonly class LoginThrottle
{
    public const WINDOW = 900;
    private const MAX_PER_IP = 5;
    private const MAX_PER_USERNAME = 10;

    public function __construct(private \PDO $pdo, private Clock $clock, private Config $config) {}

    public function isBlocked(string $ip, string $username): bool
    {
        return $this->failures($this->bucket('ip', $ip)) >= self::MAX_PER_IP
            || $this->failures($this->bucket('user', $username)) >= self::MAX_PER_USERNAME;
    }

    public function recordFailure(string $ip, string $username): void
    {
        $now = $this->clock->now();

        // Rows older than the window are useless; removing them here bounds the table.
        $this->pdo->prepare('DELETE FROM login_attempt WHERE attempted_at <= to_timestamp(?)')->execute([$now - self::WINDOW]);

        $insert = $this->pdo->prepare('INSERT INTO login_attempt (bucket, attempted_at) VALUES (?, to_timestamp(?))');
        $insert->execute([$this->bucket('ip', $ip), $now]);
        $insert->execute([$this->bucket('user', $username), $now]);
    }

    public function clear(string $ip, string $username): void
    {
        $delete = $this->pdo->prepare('DELETE FROM login_attempt WHERE bucket = ?');
        $delete->execute([$this->bucket('ip', $ip)]);
        $delete->execute([$this->bucket('user', $username)]);
    }

    private function failures(string $bucket): int
    {
        $stmt = $this->pdo->prepare('SELECT count(*) FROM login_attempt WHERE bucket = ? AND attempted_at > to_timestamp(?)');
        $stmt->execute([$bucket, $this->clock->now() - self::WINDOW]);
        $count = $stmt->fetchColumn();

        return is_numeric($count) ? (int) $count : 0;
    }

    private function bucket(string $kind, string $value): string
    {
        return hash_hmac('sha256', $kind . ':' . $value, $this->config->appSecret);
    }
}
