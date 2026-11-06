<?php

declare(strict_types=1);

namespace Edict\Database;

/**
 * Applies migrations/NNNN_name.sql in filename order, each in its own
 * transaction, and records them in schema_migration. Safe to run concurrently:
 * an advisory lock serialises runners.
 */
final readonly class Migrator
{
    private const LOCK_ID = 7_450_001;

    public function __construct(private \PDO $pdo, private string $directory) {}

    /**
     * @return list<string> versions applied by this run
     */
    public function migrate(): array
    {
        $this->pdo->exec('SELECT pg_advisory_lock(' . self::LOCK_ID . ')');

        try {
            $this->pdo->exec(
                'CREATE TABLE IF NOT EXISTS schema_migration (
                    version text PRIMARY KEY,
                    applied_at timestamptz NOT NULL DEFAULT now()
                )',
            );

            $applied = [];
            foreach ($this->pending() as $version => $path) {
                $sql = file_get_contents($path);
                if ($sql === false) {
                    throw new \RuntimeException("Cannot read migration $path");
                }

                $this->pdo->beginTransaction();

                try {
                    $this->pdo->exec($sql);
                    $this->pdo->prepare('INSERT INTO schema_migration (version) VALUES (?)')->execute([$version]);
                    $this->pdo->commit();
                } catch (\Throwable $e) {
                    $this->pdo->rollBack();

                    throw new \RuntimeException("Migration $version failed: " . $e->getMessage(), 0, $e);
                }
                $applied[] = $version;
            }

            return $applied;
        } finally {
            $this->pdo->exec('SELECT pg_advisory_unlock(' . self::LOCK_ID . ')');
        }
    }

    /**
     * @return array<string, string> version => path, in order
     */
    private function pending(): array
    {
        $done = [];
        $stmt = $this->pdo->query('SELECT version FROM schema_migration');
        if ($stmt !== false) {
            foreach ($stmt as $row) {
                if (is_array($row)) {
                    $done[Row::str($row, 'version')] = true;
                }
            }
        }

        $files = glob($this->directory . '/*.sql');
        if ($files === false) {
            throw new \RuntimeException("Cannot list migrations in {$this->directory}");
        }
        sort($files);

        $pending = [];
        foreach ($files as $path) {
            $version = basename($path, '.sql');
            if (!isset($done[$version])) {
                $pending[$version] = $path;
            }
        }

        return $pending;
    }
}
