<?php

declare(strict_types=1);

namespace Edict\Media;

/**
 * Runs queued jobs, one at a time. A job that keeps failing marks its media as
 * failed, so the editor can say so instead of showing "processing" forever.
 */
final readonly class JobRunner
{
    public function __construct(private JobQueue $jobs, private ImageProcessor $images) {}

    /**
     * @return bool whether there was a job to run
     */
    public function runNext(): bool
    {
        $job = $this->jobs->claim();
        if ($job === null) {
            return false;
        }

        try {
            $this->dispatch($job['type'], $job['payload']);
            $this->jobs->finish($job['id']);
        } catch (\Throwable $e) {
            // Only messages written for the admin reach the admin.
            $message = $e instanceof MediaError ? $e->getMessage() : 'Processing failed unexpectedly.';
            if (!$this->jobs->fail($job['id'], $e->getMessage()) && $job['type'] === ImageProcessor::JOB) {
                $this->images->markFailed(self::mediaId($job['payload']), $message);
            }
        }

        return true;
    }

    /**
     * @param array<mixed> $payload
     */
    private function dispatch(string $type, array $payload): void
    {
        match ($type) {
            ImageProcessor::JOB => $this->images->process(self::mediaId($payload)),
            default => throw new \RuntimeException("Unknown job type $type"),
        };
    }

    /**
     * @param array<mixed> $payload
     */
    private static function mediaId(array $payload): int
    {
        $id = $payload['media_id'] ?? null;

        return is_int($id) ? $id : 0;
    }
}
