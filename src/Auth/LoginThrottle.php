<?php

declare(strict_types=1);

namespace Edict\Auth;

use Edict\Clock;
use Edict\Config;

/**
 * Throttles failed logins per client IP and per username. Buckets are keyed
 * HMACs, so the table contains no IP addresses or attempted usernames. Failed
 * provider and passkey logins count separately, so that they can't lock anyone
 * out of the password form. A provider failure can also cost a blocking request.
 */
final readonly class LoginThrottle
{
    public const WINDOW = 900;
    private const MAX_PER_IP = 5;
    private const MAX_PER_USERNAME = 10;
    private const MAX_OTHER_PER_IP = 20;

    public function __construct(private \PDO $pdo, private Clock $clock, private Config $config) {}

    public function isBlocked(string $ip, string $username): bool
    {
        return $this->failures($this->ipBucket('ip', $ip)) >= self::MAX_PER_IP
            || $this->failures($this->bucket('user', $username)) >= self::MAX_PER_USERNAME;
    }

    public function recordFailure(string $ip, string $username): void
    {
        $this->record($this->ipBucket('ip', $ip), $this->bucket('user', $username));
    }

    public function isOAuthBlocked(string $ip): bool
    {
        return $this->failures($this->ipBucket('oauth-ip', $ip)) >= self::MAX_OTHER_PER_IP;
    }

    public function recordOAuthFailure(string $ip): void
    {
        $this->record($this->ipBucket('oauth-ip', $ip));
    }

    public function clear(string $ip, string $username): void
    {
        $delete = $this->pdo->prepare('DELETE FROM login_attempt WHERE bucket = ?');
        $delete->execute([$this->ipBucket('ip', $ip)]);
        $delete->execute([$this->bucket('user', $username)]);
    }

    /**
     * Lifts the username block. For admin:reset, the recovery path when someone
     * keeps failing with the admin's username.
     */
    public function clearUsername(string $username): void
    {
        $this->pdo->prepare('DELETE FROM login_attempt WHERE bucket = ?')->execute([$this->bucket('user', $username)]);
    }

    /**
     * The unit one client controls: an IPv4 address or an IPv6 /64, since one
     * connection commonly has a whole /64 to hop around in.
     */
    public static function network(string $ip): string
    {
        $packed = @inet_pton($ip);
        if ($packed === false || strlen($packed) !== 16) {
            return $ip;
        }

        // ::ffff:192.0.2.1 is an IPv4 client on a dual-stack socket.
        if (str_starts_with($packed, str_repeat("\0", 10) . "\xff\xff")) {
            return inet_ntop(substr($packed, 12)) ?: $ip;
        }

        return bin2hex(substr($packed, 0, 8)) . '::/64';
    }

    public function isPasskeyBlocked(string $ip): bool
    {
        return $this->failures($this->ipBucket('passkey-ip', $ip)) >= self::MAX_OTHER_PER_IP;
    }

    public function recordPasskeyFailure(string $ip): void
    {
        $this->record($this->ipBucket('passkey-ip', $ip));
    }

    private function ipBucket(string $kind, string $ip): string
    {
        return $this->bucket($kind, self::network($ip));
    }

    private function record(string ...$buckets): void
    {
        $now = $this->clock->now();

        // Rows older than the window are useless; removing them here bounds the table.
        $this->pdo->prepare('DELETE FROM login_attempt WHERE attempted_at <= to_timestamp(?)')->execute([$now - self::WINDOW]);

        $insert = $this->pdo->prepare('INSERT INTO login_attempt (bucket, attempted_at) VALUES (?, to_timestamp(?))');
        foreach ($buckets as $bucket) {
            $insert->execute([$bucket, $now]);
        }
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
