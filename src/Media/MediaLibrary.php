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
    private const MAX_UPLOAD_BYTES = 4_000_000_000;
    private const MAX_NAME_LENGTH = 200;

    private const COLUMNS = 'id, sha256, kind, extension, bytes, original_name, width, height, status, error';

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
     * Files the finished upload. The same content uploaded twice is stored once.
     *
     * @return array<string, mixed> the media row
     *
     * @throws MediaError
     */
    public function completeUpload(string $id): array
    {
        $upload = $this->upload($id);
        $bytes = Row::int($upload, 'total_bytes');
        if (Row::int($upload, 'received_bytes') !== $bytes) {
            throw new MediaError('The upload is not finished.');
        }

        $path = $this->store->uploadPath($id);
        $sha256 = hash_file('sha256', $path);
        if ($sha256 === false) {
            throw new MediaError('Could not read the upload.');
        }

        $existing = $this->findBySha($sha256);
        if ($existing !== null) {
            $this->dropUpload($id);

            return $existing;
        }

        $type = FileType::of($path);
        if ($type['kind'] === 'image' && $bytes > self::MAX_IMAGE_BYTES) {
            $this->dropUpload($id);

            throw new MediaError('Images can be up to ' . self::human(self::MAX_IMAGE_BYTES) . '.');
        }
        $this->assertRoomFor(0);

        $this->store->install($path, $this->store->originalPath($sha256));

        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO media (sha256, kind, extension, bytes, original_name, status, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, to_timestamp(?)) RETURNING id',
            );
            $stmt->execute([
                $sha256,
                $type['kind'],
                $type['extension'],
                $bytes,
                Row::str($upload, 'original_name'),
                $type['kind'] === 'image' ? 'processing' : 'ready',
                $this->clock->now(),
            ]);
            $mediaId = Row::int(['id' => $stmt->fetchColumn()], 'id');
            if ($type['kind'] === 'image') {
                $this->jobs->enqueue(ImageProcessor::JOB, ['media_id' => $mediaId]);
            }
            $this->pdo->prepare('DELETE FROM upload WHERE id = ?')->execute([$id]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();

            throw $e;
        }

        $media = $this->find($mediaId);
        if ($media === null) {
            throw new MediaError('The file vanished while being stored.');
        }

        return $media;
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
     * Fails loudly before anything is written: over the cap, too many files, or too
     * little free disk for what else lives on it.
     *
     * @throws MediaError
     */
    public function assertRoomFor(int $bytes): void
    {
        $free = $this->store->freeBytes();
        if ($free - $bytes < $this->config->mediaMinFreeBytes) {
            throw new MediaError('Not enough free disk space for this file.');
        }

        $stmt = $this->pdo->query(
            'SELECT (SELECT coalesce(sum(bytes), 0) FROM media) + (SELECT coalesce(sum(bytes), 0) FROM media_variant) AS used,
                    (SELECT count(*) FROM media) + (SELECT count(*) FROM media_variant) AS files',
        );
        $row = $stmt === false ? false : $stmt->fetch();
        if (!is_array($row)) {
            throw new MediaError('Could not check the storage limits.');
        }

        if (Row::int($row, 'used') + $bytes > $this->config->mediaMaxBytes) {
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
        $stmt = $this->pdo->prepare('SELECT original_name, total_bytes, received_bytes FROM upload WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            throw new MediaError('That upload no longer exists.');
        }

        return $row;
    }

    private function dropUpload(string $id): void
    {
        MediaStore::remove($this->store->uploadPath($id));
        $this->pdo->prepare('DELETE FROM upload WHERE id = ?')->execute([$id]);
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
        ];
    }

    private static function human(int $bytes): string
    {
        return round($bytes / 1_000_000_000, 1) . ' GB';
    }
}
