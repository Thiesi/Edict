<?php

declare(strict_types=1);

namespace Edict\Media;

use Edict\Clock;
use Edict\Database\Row;

/**
 * Background jobs in PostgreSQL. SKIP LOCKED lets workers claim jobs without
 * blocking each other, so no Redis or other service is needed.
 */
final readonly class JobQueue
{
    private const MAX_ATTEMPTS = 3;
    private const RETRY_DELAY = 60;
    private const STALE_AFTER = 3600;
    private const KEEP_FINISHED_FOR = 7 * 86400;

    public function __construct(private \PDO $pdo, private Clock $clock) {}

    /**
     * @param array<string, mixed> $payload
     */
    public function enqueue(string $type, array $payload): void
    {
        $now = $this->clock->now();
        $this->pdo->prepare('INSERT INTO job (type, payload, run_after, created_at) VALUES (?, ?, to_timestamp(?), to_timestamp(?))')
            ->execute([$type, json_encode($payload, JSON_THROW_ON_ERROR), $now, $now]);
    }

    /**
     * @param list<string>|null $onlyTypes leave all other jobs for another worker
     *
     * @return array{id: int, type: string, payload: array<mixed>, attempts: int}|null
     */
    public function claim(?array $onlyTypes = null): ?array
    {
        $now = $this->clock->now();
        $stmt = $this->pdo->prepare(
            "UPDATE job SET state = 'running', attempts = attempts + 1, locked_at = to_timestamp(?)
             WHERE id = (
                 SELECT id FROM job WHERE state = 'queued' AND run_after <= to_timestamp(?) AND (?::text[] IS NULL OR type = ANY(?::text[]))
                 ORDER BY run_after, id FOR UPDATE SKIP LOCKED LIMIT 1
             )
             RETURNING id, type, payload::text AS payload, attempts",
        );
        $types = $onlyTypes === null ? null : '{' . implode(',', $onlyTypes) . '}';
        $stmt->execute([$now, $now, $types, $types]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            return null;
        }

        $payload = json_decode(Row::str($row, 'payload'), true);

        return [
            'id' => Row::int($row, 'id'),
            'type' => Row::str($row, 'type'),
            'payload' => is_array($payload) ? $payload : [],
            'attempts' => Row::int($row, 'attempts'),
        ];
    }

    public function finish(int $id): void
    {
        $this->pdo->prepare("UPDATE job SET state = 'done', locked_at = NULL, error = NULL WHERE id = ?")->execute([$id]);
    }

    /**
     * @param bool $hopeless don't try again
     *
     * @return bool true if the job will run again, false if it has failed for good
     */
    public function fail(int $id, string $error, bool $hopeless = false): bool
    {
        $stmt = $this->pdo->prepare(
            "UPDATE job SET state = CASE WHEN attempts < ? AND NOT ?::boolean THEN 'queued' ELSE 'failed' END,
                    run_after = to_timestamp(?), locked_at = NULL, error = ?
             WHERE id = ? RETURNING state",
        );
        $stmt->execute([self::MAX_ATTEMPTS, $hopeless ? 'true' : 'false', $this->clock->now() + self::RETRY_DELAY, mb_substr($error, 0, 2000), $id]);

        return $stmt->fetchColumn() === 'queued';
    }

    /**
     * Requeues jobs whose worker died, unless they've used up their attempts, and
     * forgets old finished ones.
     *
     * @return list<array{type: string, payload: array<mixed>}> jobs that failed for good
     */
    public function housekeeping(): array
    {
        $now = $this->clock->now();
        $stale = $this->pdo->prepare(
            "UPDATE job SET state = CASE WHEN attempts < ? THEN 'queued' ELSE 'failed' END, locked_at = NULL,
                    error = 'The worker stopped during this job.'
             WHERE state = 'running' AND locked_at < to_timestamp(?)
             RETURNING state, type, payload::text AS payload",
        );
        $stale->execute([self::MAX_ATTEMPTS, $now - self::STALE_AFTER]);
        $failed = [];
        foreach ($stale->fetchAll() as $row) {
            if (is_array($row) && Row::str($row, 'state') === 'failed') {
                $payload = json_decode(Row::str($row, 'payload'), true);
                $failed[] = ['type' => Row::str($row, 'type'), 'payload' => is_array($payload) ? $payload : []];
            }
        }

        $this->pdo->prepare("DELETE FROM job WHERE state = 'done' AND created_at < to_timestamp(?)")
            ->execute([$now - self::KEEP_FINISHED_FOR]);

        return $failed;
    }
}
