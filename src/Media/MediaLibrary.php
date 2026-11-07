<?php

declare(strict_types=1);

namespace Edict\Media;

use Edict\Clock;
use Edict\Config;
use Edict\Database\Row;

/**
 * Stored media and chunked uploads. Every limit is checked before anything is
 * written, and fails with a message the admin can act on.
 */
final readonly class MediaLibrary
{
    public const CHUNK_BYTES = 8_388_608;

    private const MAX_IMAGE_BYTES = 50_000_000;
    private const MAX_AUDIO_BYTES = 500_000_000;
    private const MAX_UPLOAD_BYTES = 4_000_000_000;
    private const MAX_NAME_LENGTH = 200;
    private const MAX_OPEN_UPLOADS = 20;

    private const COLUMNS = 'id, sha256, kind, extension, bytes, original_name, width, height, status, error, meta::text AS meta';

    public function __construct(
        private \PDO $pdo,
        private MediaStore $store,
        private JobQueue $jobs,
        private Clock $clock,
        private Config $config,
    ) {}

    /**
     * @return string the upload's ID
     *
     * @throws MediaError
     */
    public function beginUpload(string $name, int $bytes): string
    {
        if ($bytes <= 0 || $bytes > self::MAX_UPLOAD_BYTES) {
            throw new MediaError('That file is too large (limit: ' . self::human(self::MAX_UPLOAD_BYTES) . ').');
        }

        $open = $this->pdo->query('SELECT count(*) FROM upload WHERE media_id IS NULL');
        if (Row::int(['n' => $open === false ? 0 : $open->fetchColumn()], 'n') >= self::MAX_OPEN_UPLOADS) {
            throw new MediaError('Too many unfinished uploads. Wait a moment and try again.');
        }
        $this->assertRoomFor($bytes);

        $id = bin2hex(random_bytes(16));
        touch($this->store->uploadPath($id));
        chmod($this->store->uploadPath($id), 0o600);

        $clean = preg_replace('/[\x00-\x1F\x7F\/\\\\]+/u', '', $name);
        $this->pdo->prepare('INSERT INTO upload (id, original_name, total_bytes, created_at) VALUES (?, ?, ?, to_timestamp(?))')
            ->execute([$id, mb_substr($clean === null || $clean === '' ? 'upload' : $clean, 0, self::MAX_NAME_LENGTH), $bytes, $this->clock->now()]);

        return $id;
    }

    /**
     * @return int how many bytes have arrived, which is where a client resumes
     *
     * @throws MediaError
     */
    public function receivedBytes(string $id): int
    {
        return Row::int($this->upload($id), 'received_bytes');
    }

    /**
     * Appends one chunk. A chunk for the wrong position is refused and the answer
     * tells the client where to resume, so a repeated chunk can't corrupt the file.
     *
     * @param resource $chunk
     *
     * @return int how many bytes have arrived
     *
     * @throws MediaError
     */
    public function appendChunk(string $id, int $offset, $chunk): int
    {
        $upload = $this->upload($id);
        $received = Row::int($upload, 'received_bytes');
        if ($offset !== $received) {
            return $received;
        }

        $path = $this->store->uploadPath($id);
        $out = fopen($path, 'c');
        if ($out === false || fseek($out, $received) !== 0 || !ftruncate($out, max(0, $received))) {
            throw new MediaError('Could not write the upload.');
        }

        $room = min(self::CHUNK_BYTES, Row::int($upload, 'total_bytes') - $received);

        // Checked again per chunk: the disk is shared, and others may have filled it.
        if ($this->store->freeBytes() - $room < $this->config->mediaMinFreeBytes) {
            fclose($out);

            throw new MediaError('Not enough free disk space to continue this upload.');
        }

        try {
            $written = stream_copy_to_stream($chunk, $out, $room + 1);
        } finally {
            fclose($out);
        }
        if ($written === false || $written > $room) {
            // Too much: drop the chunk and let the client see where things stand.
            $file = fopen($path, 'c');
            if ($file !== false) {
                ftruncate($file, max(0, $received));
                fclose($file);
            }

            throw new MediaError('That chunk is larger than expected.');
        }

        $total = $received + $written;
        $this->pdo->prepare('UPDATE upload SET received_bytes = ? WHERE id = ?')->execute([$total, $id]);

        return $total;
    }

    /**
     * Files the finished upload. The same content uploaded twice is stored once, and
     * re-uploading an image that failed tries it again. Asking again gets the same
     * answer, so a lost response can't strand a stored file.
     *
     * @return array<string, mixed> the media row
     *
     * @throws MediaError
     */
    public function completeUpload(string $id): array
    {
        $installed = null;
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare('SELECT original_name, total_bytes, received_bytes, media_id FROM upload WHERE id = ? FOR UPDATE');
            $stmt->execute([$id]);
            $upload = $stmt->fetch();
            if (!is_array($upload)) {
                throw new MediaError('That upload no longer exists.');
            }

            $mediaId = Row::intOrNull($upload, 'media_id');
            if ($mediaId === null) {
                [$mediaId, $installed] = $this->fileUpload($id, $upload);
                $this->pdo->prepare('UPDATE upload SET media_id = ? WHERE id = ?')->execute([$mediaId, $id]);
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            if ($installed !== null) {
                // The database doesn't know this file, so the upload gets it back.
                @rename($installed, $this->store->uploadPath($id));
            }

            throw $e;
        }

        $media = $this->find($mediaId);
        if ($media === null) {
            throw new MediaError('The file vanished while being stored.');
        }

        return $media;
    }

    /**
     * @param array<mixed> $upload
     *
     * @return array{int, string|null} the media ID, and where a new original went
     */
    private function fileUpload(string $id, array $upload): array
    {
        $bytes = Row::int($upload, 'total_bytes');
        if (Row::int($upload, 'received_bytes') !== $bytes) {
            throw new MediaError('The upload is not finished.');
        }

        $path = $this->store->uploadPath($id);
        $sha256 = hash_file('sha256', $path);
        if ($sha256 === false) {
            throw new MediaError('Could not read the upload.');
        }

        $existing = $this->idOfSha($sha256);
        if ($existing !== null) {
            MediaStore::remove($path);
            $this->retryIfFailed($existing);

            return [$existing, null];
        }

        $type = FileType::of($path);
        if ($type['kind'] === 'image' && $bytes > self::MAX_IMAGE_BYTES) {
            throw new MediaError('Images can be up to ' . self::human(self::MAX_IMAGE_BYTES) . '.');
        }
        if ($type['kind'] === 'audio' && $bytes > self::MAX_AUDIO_BYTES) {
            throw new MediaError('Audio files can be up to ' . self::human(self::MAX_AUDIO_BYTES) . '.');
        }
        $this->assertRoomFor($bytes, $id);

        // ON CONFLICT: the same file finishing at the same moment in another upload.
        $stmt = $this->pdo->prepare(
            'INSERT INTO media (sha256, kind, extension, bytes, original_name, status, created_at)
             VALUES (?, ?, ?, ?, ?, ?, to_timestamp(?)) ON CONFLICT (sha256) DO NOTHING RETURNING id',
        );
        $stmt->execute([
            $sha256,
            $type['kind'],
            $type['extension'],
            $bytes,
            Row::str($upload, 'original_name'),
            $type['kind'] === 'file' ? 'ready' : 'processing',
            $this->clock->now(),
        ]);
        $inserted = $stmt->fetchColumn();
        if ($inserted === false) {
            $winner = $this->idOfSha($sha256);
            if ($winner === null) {
                throw new MediaError('Could not store the file. Please try again.');
            }
            MediaStore::remove($path);

            return [$winner, null];
        }

        $mediaId = Row::int(['id' => $inserted], 'id');
        $original = $this->store->originalPath($sha256);
        $this->store->install($path, $original);
        if ($type['kind'] !== 'file') {
            $this->jobs->enqueue($type['kind'] === 'image' ? ImageProcessor::JOB : AvProcessor::JOB, ['media_id' => $mediaId]);
        }

        return [$mediaId, $original];
    }

    private function idOfSha(string $sha256): ?int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM media WHERE sha256 = ?');
        $stmt->execute([$sha256]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : Row::int(['id' => $id], 'id');
    }

    private function retryIfFailed(int $mediaId): void
    {
        // Also what's stuck in "processing" with no job left, which a rollback to a release
        // that doesn't know the job type can cause. Nothing else would ever get it moving.
        $stmt = $this->pdo->prepare(
            "UPDATE media SET status = 'processing', error = NULL
             WHERE id = ? AND (status = 'failed' OR (status = 'processing' AND NOT EXISTS (
                 SELECT 1 FROM job WHERE state IN ('queued', 'running') AND (payload->>'media_id')::bigint = media.id
             )))
             RETURNING kind",
        );
        $stmt->execute([$mediaId]);
        $kind = $stmt->fetchColumn();
        if ($kind !== false) {
            $this->jobs->enqueue($kind === 'image' ? ImageProcessor::JOB : AvProcessor::JOB, ['media_id' => $mediaId]);
        }
    }

    /**
     * Gives up on an upload, which frees the room it had reserved.
     *
     * @throws MediaError
     */
    public function abortUpload(string $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM upload WHERE id = ? AND media_id IS NULL RETURNING id');
        $stmt->execute([$id]);
        if ($stmt->fetchColumn() !== false) {
            MediaStore::remove($this->store->uploadPath($id));
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT ' . self::COLUMNS . ' FROM media WHERE id = ?');
        $stmt->execute([$id]);

        return self::one($stmt);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findBySha(string $sha256): ?array
    {
        $stmt = $this->pdo->prepare('SELECT ' . self::COLUMNS . ' FROM media WHERE sha256 = ?');
        $stmt->execute([$sha256]);

        return self::one($stmt);
    }

    /**
     * @param list<int> $ids
     *
     * @return array<int, array<string, mixed>> by ID
     */
    public function ready(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $stmt = $this->pdo->prepare(
            'SELECT ' . self::COLUMNS . " FROM media WHERE status = 'ready' AND id = ANY(?::bigint[])",
        );
        $stmt->execute(['{' . implode(',', $ids) . '}']);

        $media = [];
        foreach ($stmt->fetchAll() as $row) {
            if (is_array($row)) {
                $media[Row::int($row, 'id')] = $this->describe($row);
            }
        }

        return $media;
    }

    /**
     * @return list<array<string, mixed>> newest first
     */
    public function latest(int $limit, int $offset): array
    {
        $stmt = $this->pdo->prepare('SELECT ' . self::COLUMNS . ' FROM media ORDER BY id DESC LIMIT ? OFFSET ?');
        $stmt->execute([$limit, $offset]);

        $media = [];
        foreach ($stmt->fetchAll() as $row) {
            if (is_array($row)) {
                $media[] = $this->describe($row);
            }
        }

        return $media;
    }

    /**
     * @return list<array{preset: string, format: string, width: int, height: int, url: string}>
     */
    public function variants(int $mediaId, string $sha256): array
    {
        $stmt = $this->pdo->prepare('SELECT preset, format, width, height FROM media_variant WHERE media_id = ? ORDER BY width, preset');
        $stmt->execute([$mediaId]);

        $variants = [];
        foreach ($stmt->fetchAll() as $row) {
            if (is_array($row)) {
                $preset = Row::str($row, 'preset');
                $format = Row::str($row, 'format');
                $variants[] = [
                    'preset' => $preset,
                    'format' => $format,
                    'width' => Row::int($row, 'width'),
                    'height' => Row::int($row, 'height'),
                    'url' => '/media/' . MediaStore::relativeVariantPath($sha256, $preset, $format),
                ];
            }
        }

        return $variants;
    }

    /**
     * Makes finished versions public, all at once. The rows always cover what's there:
     * a crash in between leaves names a retry overwrites.
     *
     * @param list<array{preset: string, format: string, width: int, height: int, bytes: int, file: string}> $made
     * @param string|null $kind what the job found inside, where the upload could only guess
     * @param array<string, mixed> $meta
     */
    public function publish(int $mediaId, string $sha256, array $made, int $width, int $height, ?string $kind = null, array $meta = []): void
    {
        // The caps only: these files are on this disk already, in the job's directory.
        $this->assertRoomFor(array_sum(array_column($made, 'bytes')), null, true);

        $this->pdo->beginTransaction();
        $installed = [];
        try {
            $this->pdo->prepare('DELETE FROM media_variant WHERE media_id = ?')->execute([$mediaId]);
            $insert = $this->pdo->prepare(
                'INSERT INTO media_variant (media_id, preset, format, width, height, bytes) VALUES (?, ?, ?, ?, ?, ?)',
            );
            foreach ($made as $variant) {
                $insert->execute([$mediaId, $variant['preset'], $variant['format'], $variant['width'], $variant['height'], $variant['bytes']]);
                $target = $this->store->variantPath($sha256, $variant['preset'], $variant['format']);
                $this->store->install($variant['file'], $target);
                $installed[] = $target;
            }
            $this->pdo->prepare(
                "UPDATE media SET status = 'ready', error = NULL, width = ?, height = ?, kind = coalesce(?, kind), meta = ?::jsonb WHERE id = ?",
            )->execute([$width, $height, $kind, json_encode((object) $meta, JSON_THROW_ON_ERROR), $mediaId]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            foreach ($installed as $path) {
                MediaStore::remove($path);
            }

            throw $e;
        }
    }

    public function markFailed(int $mediaId, string $error): void
    {
        $this->pdo->prepare("UPDATE media SET status = 'failed', error = ? WHERE id = ?")->execute([mb_substr($error, 0, 500), $mediaId]);
    }

    /**
     * Fails loudly before anything is written: over the cap, too many files, or too
     * little free disk for what else lives on it. Unfinished uploads count for their
     * full size, so several at once can't each pass and then overfill the disk.
     *
     * @param string|null $exceptUpload an upload whose own reservation this replaces
     * @param bool $onDiskAlready skip the free disk check, because nothing new gets written
     *
     * @throws MediaError
     */
    public function assertRoomFor(int $bytes, ?string $exceptUpload = null, bool $onDiskAlready = false): void
    {
        $stmt = $this->pdo->prepare(
            'SELECT (SELECT coalesce(sum(bytes), 0) FROM media) + (SELECT coalesce(sum(bytes), 0) FROM media_variant) AS used,
                    (SELECT count(*) FROM media) + (SELECT count(*) FROM media_variant) AS files,
                    (SELECT coalesce(sum(total_bytes), 0) FROM upload WHERE media_id IS NULL AND id IS DISTINCT FROM ?) AS reserved,
                    (SELECT coalesce(sum(total_bytes - received_bytes), 0) FROM upload WHERE media_id IS NULL AND id IS DISTINCT FROM ?) AS incoming',
        );
        $stmt->execute([$exceptUpload, $exceptUpload]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            throw new MediaError('Could not check the storage limits.');
        }

        // Only bytes still to arrive take disk from here on.
        $coming = Row::int($row, 'incoming') + ($exceptUpload === null ? $bytes : 0);
        if (!$onDiskAlready && $this->store->freeBytes() - $coming < $this->config->mediaMinFreeBytes) {
            throw new MediaError('Not enough free disk space for this file.');
        }
        if (Row::int($row, 'used') + Row::int($row, 'reserved') + $bytes > $this->config->mediaMaxBytes) {
            throw new MediaError('The media storage limit (' . self::human($this->config->mediaMaxBytes) . ') would be exceeded.');
        }
        if (Row::int($row, 'files') >= $this->config->mediaMaxFiles) {
            throw new MediaError('The media file limit (' . $this->config->mediaMaxFiles . ') has been reached.');
        }
    }

    /**
     * Forgets abandoned uploads. Their temp files go with MediaStore::sweep().
     */
    public function forgetUploadsOlderThan(int $timestamp): void
    {
        $this->pdo->prepare('DELETE FROM upload WHERE created_at < to_timestamp(?)')->execute([$timestamp]);
    }

    /**
     * @return array<mixed>
     */
    private function upload(string $id): array
    {
        $stmt = $this->pdo->prepare('SELECT total_bytes, received_bytes FROM upload WHERE id = ? AND media_id IS NULL');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            throw new MediaError('That upload no longer exists.');
        }

        return $row;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function one(\PDOStatement $stmt): ?array
    {
        $row = $stmt->fetch();

        return is_array($row) ? $this->describe($row) : null;
    }

    /**
     * @param array<mixed> $row
     *
     * @return array<string, mixed>
     */
    private function describe(array $row): array
    {
        $id = Row::int($row, 'id');
        $sha256 = Row::str($row, 'sha256');
        $meta = json_decode(is_string($row['meta'] ?? null) ? $row['meta'] : '{}', true);
        $meta = is_array($meta) ? $meta : [];

        return [
            'id' => $id,
            'kind' => Row::str($row, 'kind'),
            'name' => Row::str($row, 'original_name'),
            'bytes' => Row::int($row, 'bytes'),
            'width' => Row::intOrNull($row, 'width'),
            'height' => Row::intOrNull($row, 'height'),
            'status' => Row::str($row, 'status'),
            'error' => is_string($row['error'] ?? null) ? $row['error'] : null,
            'variants' => $this->variants($id, $sha256),
            'seconds' => is_numeric($meta['seconds'] ?? null) ? (float) $meta['seconds'] : null,
            'peaks' => is_array($meta['peaks'] ?? null) ? $meta['peaks'] : [],
        ];
    }

    private static function human(int $bytes): string
    {
        return round($bytes / 1_000_000_000, 1) . ' GB';
    }
}
