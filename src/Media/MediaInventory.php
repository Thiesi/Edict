<?php

declare(strict_types=1);

namespace Edict\Media;

use Edict\Database\Row;

/**
 * Everything stored, where each item is used, and removing what nobody needs.
 *
 * "In use" means the text, draft or cover of any article or page, the trash included,
 * or the site's logo or icon. Published versions kept for restoring don't count: they
 * may lose an image, which then simply isn't there, in the editor or for readers.
 */
final readonly class MediaInventory
{
    /** Every media ID anywhere in a document. Body::clean() keeps them only where they belong. */
    private const IDS_IN = 'lax $.**.mediaId ? (@.type() == "number")';

    /** The jobs that work on one media item, by its ID in the payload. */
    private const MEDIA_JOBS = [ImageProcessor::JOB, AvProcessor::JOB, AvProcessor::POSTER_JOB, FileProcessor::JOB, IconProcessor::JOB];

    private const SITE_PLACES = ['logo' => 'logo', 'favicon' => 'site icon'];

    public function __construct(private \PDO $pdo, private MediaStore $store) {}

    /**
     * Every item, with a small picture where there is one.
     *
     * @param bool $unusedOnly only what could be deleted
     * @param string $sort "newest" or "size" (largest first)
     *
     * @return list<array{id: int, kind: string, name: string, bytes: int, storedBytes: int, size: string, storedSize: string, status: string, error: string|null, uploadedAt: int, thumb: array{url: string, width: int, height: int}|null, uses: list<array{type: string, title: string, url: string, places: list<string>, trashed: bool}>, oldVersions: int}>
     */
    public function items(bool $unusedOnly = false, string $sort = 'newest'): array
    {
        $order = $sort === 'size' ? 'stored_bytes DESC, m.id DESC' : 'm.id DESC';
        $stmt = $this->pdo->query(
            "SELECT m.id, m.sha256, m.kind, m.original_name, m.bytes, m.status, m.error,
                    EXTRACT(EPOCH FROM m.created_at)::bigint AS created_at,
                    m.bytes + coalesce(v.bytes, 0) AS stored_bytes,
                    t.preset AS thumb_preset, t.format AS thumb_format, t.width AS thumb_width, t.height AS thumb_height
             FROM media m
             LEFT JOIN (SELECT media_id, sum(bytes) AS bytes FROM media_variant GROUP BY media_id) v ON v.media_id = m.id
             LEFT JOIN LATERAL (
                 SELECT preset, format, width, height FROM media_variant mv
                 WHERE mv.media_id = m.id AND (mv.preset IN ('w480-webp', 'w480-jpg', 'preview') OR mv.preset ~ '^poster(-[0-9]+)?$')
                 ORDER BY CASE mv.preset WHEN 'w480-webp' THEN 0 WHEN 'w480-jpg' THEN 1 ELSE 2 END
                 LIMIT 1
             ) t ON true
             ORDER BY $order",
        );

        $uses = $this->uses();
        $old = $this->oldVersions();
        $items = [];
        foreach ($stmt === false ? [] : $stmt->fetchAll() as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = Row::int($row, 'id');
            if ($unusedOnly && isset($uses[$id])) {
                continue;
            }
            $thumb = null;
            if (is_string($row['thumb_preset'] ?? null) && is_string($row['thumb_format'] ?? null)) {
                $thumb = [
                    'url' => '/media/' . MediaStore::relativeVariantPath(Row::str($row, 'sha256'), $row['thumb_preset'], $row['thumb_format']),
                    'width' => Row::int($row, 'thumb_width'),
                    'height' => Row::int($row, 'thumb_height'),
                ];
            }
            $items[] = [
                'id' => $id,
                'kind' => Row::str($row, 'kind'),
                'name' => Row::str($row, 'original_name'),
                'bytes' => Row::int($row, 'bytes'),
                'storedBytes' => Row::int($row, 'stored_bytes'),
                'size' => self::human(Row::int($row, 'bytes')),
                'storedSize' => self::human(Row::int($row, 'stored_bytes')),
                'status' => Row::str($row, 'status'),
                'error' => is_string($row['error'] ?? null) ? $row['error'] : null,
                'uploadedAt' => Row::int($row, 'created_at'),
                'thumb' => $thumb,
                'uses' => $uses[$id] ?? [],
                'oldVersions' => $old[$id] ?? 0,
            ];
        }

        return $items;
    }

    /**
     * Where one item is used. Empty means it may be deleted.
     *
     * @return list<array{type: string, title: string, url: string, places: list<string>, trashed: bool}>
     */
    public function usesOf(int $mediaId): array
    {
        return $this->uses($mediaId)[$mediaId] ?? [];
    }

    /**
     * How many of the published versions kept for restoring show this item.
     */
    public function oldVersionsOf(int $mediaId): int
    {
        return $this->oldVersions($mediaId)[$mediaId] ?? 0;
    }

    /**
     * Removes an item nobody uses: its rows, its waiting jobs, and its files. Checked
     * here, whatever the page showed, since something may have started using it since.
     *
     * @return string the name it had, to say what was deleted
     *
     * @throws MediaError when it is in use, being processed, or already gone
     */
    public function delete(int $mediaId): string
    {
        $this->pdo->beginTransaction();
        try {
            // Locked, so two deletes or a starting job can't slip in between.
            $stmt = $this->pdo->prepare('SELECT sha256, original_name FROM media WHERE id = ? FOR UPDATE');
            $stmt->execute([$mediaId]);
            $media = $stmt->fetch();
            if (!is_array($media)) {
                throw new MediaError('That file was already deleted.');
            }
            $sha256 = Row::str($media, 'sha256');

            $uses = $this->usesOf($mediaId);
            if ($uses !== []) {
                throw new MediaError('Not deleted: it is still used in ' . self::describe($uses) . '. Remove it there first.');
            }

            $types = '{' . implode(',', self::MEDIA_JOBS) . '}';
            $payload = json_encode(['media_id' => $mediaId], JSON_THROW_ON_ERROR);
            $running = $this->pdo->prepare("SELECT 1 FROM job WHERE state = 'running' AND type = ANY(?::text[]) AND payload @> ?::jsonb");
            $running->execute([$types, $payload]);
            if ($running->fetchColumn() !== false) {
                throw new MediaError('Not deleted: it is being processed right now. Try again in a few minutes.');
            }

            // The paths come from these rows and the hash, never from the request.
            $variants = $this->pdo->prepare('SELECT preset, format FROM media_variant WHERE media_id = ?');
            $variants->execute([$mediaId]);
            $files = [];
            foreach ($variants->fetchAll() as $row) {
                if (is_array($row)) {
                    $files[] = ['preset' => Row::str($row, 'preset'), 'format' => Row::str($row, 'format')];
                }
            }

            $this->pdo->prepare("DELETE FROM job WHERE state = 'queued' AND type = ANY(?::text[]) AND payload @> ?::jsonb")->execute([$types, $payload]);
            // A finished upload whose media is gone would otherwise look unfinished and hold its room.
            $this->pdo->prepare('DELETE FROM upload WHERE media_id = ?')->execute([$mediaId]);
            $this->pdo->prepare('DELETE FROM media_variant WHERE media_id = ?')->execute([$mediaId]);
            $this->pdo->prepare('DELETE FROM media WHERE id = ?')->execute([$mediaId]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();

            throw $e;
        }

        // Only once the rows are gone, and only if no other row has the same content.
        // sha256 is unique, but the same file may have been uploaded again just now.
        $again = $this->pdo->prepare('SELECT 1 FROM media WHERE sha256 = ?');
        $again->execute([$sha256]);
        if ($again->fetchColumn() === false) {
            $this->store->discard($sha256, $files);
        }

        return Row::str($media, 'original_name');
    }

    /**
     * @param list<array{type: string, title: string, url: string, places: list<string>, trashed: bool}> $uses
     */
    public static function describe(array $uses): string
    {
        $names = array_map(
            static fn(array $use): string => $use['type'] === 'site' ? 'the site look' : '“' . $use['title'] . '”',
            array_slice($uses, 0, 3),
        );
        $more = count($uses) - count($names);

        return implode(', ', $names) . ($more > 0 ? " and $more more" : '');
    }

    /**
     * @return array<int, list<array{type: string, title: string, url: string, places: list<string>, trashed: bool}>> by media ID
     */
    private function uses(?int $only = null): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT r.media_id, r.place, a.id, a.kind, a.deleted_at IS NOT NULL AS trashed,
                    coalesce(nullif(a.draft ->> 'title', ''), a.title) AS title
             FROM article a
             CROSS JOIN LATERAL (
                 SELECT (v #>> '{}')::numeric::bigint AS media_id, 'text' AS place
                 FROM jsonb_path_query(a.body_json, '" . self::IDS_IN . "') AS v
                 UNION
                 SELECT (v #>> '{}')::numeric::bigint, 'draft'
                 FROM jsonb_path_query(a.draft -> 'body', '" . self::IDS_IN . "') AS v
                 UNION
                 SELECT a.cover_media_id, 'cover' WHERE a.cover_media_id IS NOT NULL
                 UNION
                 SELECT (a.draft ->> 'cover')::numeric::bigint, 'draft cover' WHERE jsonb_typeof(a.draft -> 'cover') = 'number'
             ) AS r
             WHERE ?::bigint IS NULL OR r.media_id = ?::bigint
             ORDER BY a.deleted_at IS NOT NULL, a.kind, a.id, r.place",
        );
        $stmt->execute([$only, $only]);

        $uses = [];
        foreach ($stmt->fetchAll() as $row) {
            if (!is_array($row)) {
                continue;
            }
            $mediaId = Row::int($row, 'media_id');
            $articleId = Row::int($row, 'id');
            $kind = Row::str($row, 'kind') === 'page' ? 'page' : 'article';
            $trashed = Row::bool($row, 'trashed');
            $key = "a$articleId";
            $title = Row::str($row, 'title');
            $uses[$mediaId][$key] ??= [
                'type' => $kind,
                'title' => $title === '' ? 'Untitled' : $title,
                'url' => $trashed ? "/admin/articles?trash=1&kind=$kind" : "/admin/articles/$articleId",
                'places' => [],
                'trashed' => $trashed,
            ];
            $uses[$mediaId][$key]['places'][] = Row::str($row, 'place');
        }

        $settings = $this->pdo->query("SELECT key, value::text AS value FROM setting WHERE key IN ('logo', 'favicon') ORDER BY key DESC");
        foreach ($settings === false ? [] : $settings->fetchAll() as $row) {
            $value = is_array($row) ? json_decode(Row::str($row, 'value'), true) : null;
            if (!is_array($row) || !is_int($value) || $value <= 0 || ($only !== null && $value !== $only)) {
                continue;
            }
            $uses[$value]['site'] ??= ['type' => 'site', 'title' => 'Site look', 'url' => '/admin/themes#look', 'places' => [], 'trashed' => false];
            $uses[$value]['site']['places'][] = self::SITE_PLACES[Row::str($row, 'key')] ?? Row::str($row, 'key');
        }

        return array_map(array_values(...), $uses);
    }

    /**
     * @return array<int, int> how many kept versions show each item, by media ID
     */
    private function oldVersions(?int $only = null): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT r.media_id, count(DISTINCT rev.id) AS versions
             FROM article_revision rev
             CROSS JOIN LATERAL (
                 SELECT (v #>> '{}')::numeric::bigint AS media_id
                 FROM jsonb_path_query(rev.content -> 'body', '" . self::IDS_IN . "') AS v
                 UNION
                 SELECT (rev.content ->> 'cover')::numeric::bigint WHERE jsonb_typeof(rev.content -> 'cover') = 'number'
             ) AS r
             WHERE ?::bigint IS NULL OR r.media_id = ?::bigint
             GROUP BY r.media_id",
        );
        $stmt->execute([$only, $only]);

        $counts = [];
        foreach ($stmt->fetchAll() as $row) {
            if (is_array($row)) {
                $counts[Row::int($row, 'media_id')] = Row::int($row, 'versions');
            }
        }

        return $counts;
    }

    public static function human(int $bytes): string
    {
        foreach (['GB' => 1_000_000_000, 'MB' => 1_000_000, 'KB' => 1_000] as $unit => $factor) {
            if ($bytes >= $factor) {
                return rtrim(rtrim(number_format($bytes / $factor, 1, '.', ''), '0'), '.') . ' ' . $unit;
            }
        }

        return $bytes . ' bytes';
    }
}
