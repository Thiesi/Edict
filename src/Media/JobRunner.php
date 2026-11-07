<?php

declare(strict_types=1);

namespace Edict\Media;

/**
 * Runs queued jobs, one at a time. A job that keeps failing marks its media as
 * failed, so the editor can say so instead of showing "processing" forever.
 */
final readonly class JobRunner
{
    public function __construct(
        private JobQueue $jobs,
        private ImageProcessor $images,
        private AvProcessor $av,
        private MediaLibrary $library,
    ) {}

    /**
     * @param bool $imagesOnly for the second worker, which keeps images moving while the
     *                         first one spends hours on a video
     *
     * @return bool whether there was a job to run
     */
    public function runNext(bool $imagesOnly = false): bool
    {
        $job = $this->jobs->claim($imagesOnly ? ImageProcessor::JOB : null);
        if ($job === null) {
            return false;
        }

        try {
            $this->dispatch($job['type'], $job['payload']);
            $this->jobs->finish($job['id']);
        } catch (\Throwable $e) {
            // Only messages written for the admin reach the admin.
            $message = $e instanceof MediaError ? $e->getMessage() : 'Processing failed unexpectedly.';
            $hopeless = $e instanceof MediaError && $e->hopeless;
            if (!$this->jobs->fail($job['id'], $e->getMessage(), $hopeless) && self::isAboutMedia($job['type'])) {
                $this->library->markFailed(self::mediaId($job['payload']), $message);
            }
        }

        return true;
    }

    public function housekeeping(): void
    {
        foreach ($this->jobs->housekeeping() as $job) {
            if (self::isAboutMedia($job['type'])) {
                $this->library->markFailed(self::mediaId($job['payload']), 'Processing stopped unexpectedly. Upload the file again to retry.');
            }
        }
    }

    /**
     * @param array<mixed> $payload
     */
    private function dispatch(string $type, array $payload): void
    {
        match ($type) {
            ImageProcessor::JOB => $this->images->process(self::mediaId($payload)),
            AvProcessor::JOB => $this->av->process(self::mediaId($payload)),
            default => throw new \RuntimeException("Unknown job type $type"),
        };
    }

    private static function isAboutMedia(string $type): bool
    {
        return $type === ImageProcessor::JOB || $type === AvProcessor::JOB;
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
