<?php

declare(strict_types=1);

namespace Edict\Database;

/**
 * Creates PDO connections from a URL like pgsql://user:pass@host:port/dbname.
 */
final class ConnectionFactory
{
    public static function fromUrl(string $url): \PDO
    {
        $parts = parse_url($url);
        if ($parts === false || !in_array($parts['scheme'] ?? '', ['pgsql', 'postgres', 'postgresql'], true)) {
            throw new \InvalidArgumentException('DATABASE_URL must be a pgsql:// URL');
        }

        $dbname = ltrim($parts['path'] ?? '', '/');
        if ($dbname === '') {
            throw new \InvalidArgumentException('DATABASE_URL has no database name');
        }

        $dsn = sprintf(
            'pgsql:host=%s;port=%d;dbname=%s',
            $parts['host'] ?? '127.0.0.1',
            $parts['port'] ?? 5432,
            rawurldecode($dbname),
        );

        return new \PDO(
            $dsn,
            isset($parts['user']) ? rawurldecode($parts['user']) : null,
            isset($parts['pass']) ? rawurldecode($parts['pass']) : null,
            [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_EMULATE_PREPARES => false,
                \PDO::ATTR_TIMEOUT => 5,
            ],
        );
    }
}
