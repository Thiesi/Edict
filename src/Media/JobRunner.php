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
        // A new poster takes seconds, so it needn't wait hours behind a video either.
        $job = $this->jobs->claim($imagesOnly ? [ImageProcessor::JOB, AvProcessor::POSTER_JOB] : null);
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
            if (!$this->jobs->fail($job['id'], $e->getMessage(), $hopeless)) {
                $this->gaveUp($job['type'], $job['payload'], $message);
            }
        }

        return true;
    }

    public function housekeeping(): void
    {
        foreach ($this->jobs->housekeeping() as $job) {
            $this->gaveUp($job['type'], $job['payload'], $job['type'] === AvProcessor::POSTER_JOB
                ? 'Making the poster stopped unexpectedly. Try again.'
                : 'Processing stopped unexpectedly. Upload the file again to retry.');
        }
    }

    /**
     * A failed poster leaves the video as it was, and only says so.
     *
     * @param array<mixed> $payload
     */
    private function gaveUp(string $type, array $payload, string $message): void
    {
        if ($type === AvProcessor::POSTER_JOB) {
            $this->library->posterFailed(self::mediaId($payload), $message);
        } elseif ($type === ImageProcessor::JOB || $type === AvProcessor::JOB) {
            $this->library->markFailed(self::mediaId($payload), $message);
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
            AvProcessor::POSTER_JOB => $this->av->choosePoster(self::mediaId($payload), is_numeric($payload['seconds'] ?? null) ? (float) $payload['seconds'] : 0.0),
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
