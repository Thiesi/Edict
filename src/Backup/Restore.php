<?php

declare(strict_types=1);

namespace Edict\Backup;

use Edict\Clock;
use Edict\Config;
use Edict\Content\ArticleInput;
use Edict\Content\Body;
use Edict\Content\Slugs;
use Edict\Media\IconProcessor;
use Edict\Media\JobQueue;
use Edict\Media\MediaError;
use Edict\Media\MediaLibrary;
use Edict\Media\MediaStore;
use Edict\Site\Settings;

/**
 * Reads an export back into an install that has no articles, pages or media yet:
 * the case after losing the database. Everything is checked before anything is
 * written, and all of it is written in one transaction, so a refused restore
 * leaves the install exactly as it was.
 *
 * Ids are kept, so the media references in article bodies and the links between
 * rows stay right. Media rows come back only where the original is on disk; the
 * worker then makes their thumbnails and other versions again.
 *
 * @phpstan-type MediaRow array{id: int, sha256: string, kind: string, extension: string, bytes: int, name: string, width: int|null, height: int|null, created: int, poster: float|null}
 * @phpstan-type ArticleRow array{id: int, kind: string, status: string, slug: string, lang: string, title: string, teaser: string, body: string, tags: list<string>, cover: int|null, coverAlt: string, published: int|null, revised: int|null, updated: int, created: int, deleted: int|null, comments: string, oldSlugs: list<string>, draft: string|null}
 */
final readonly class Restore
{
    /** Far above any real export; the whole file is held in memory. */
    public const MAX_BYTES = 200_000_000;

    private const KINDS = ['article', 'page'];
    private const STATUSES = ['draft', 'published', 'unpublished'];
    private const COMMENT_MODES = ['site', 'open', 'hold', 'closed'];
    private const MEDIA_KINDS = ['image', 'audio', 'video', 'file'];
    private const MAX_TITLE = 200;
    private const MAX_TEASER = 1000;
    private const MAX_COVER_ALT = 500;
    private const MAX_TAG = 64;
    private const MAX_NAME = 200;

    public function __construct(
        private \PDO $pdo,
        private MediaStore $store,
        private JobQueue $jobs,
        private Settings $settings,
        private Config $config,
        private Clock $clock,
    ) {}

    /**
     * Whether a restore can go in: no articles, pages or media yet.
     */
    public function isPossible(): bool
    {
        $stmt = $this->pdo->query('SELECT CASE WHEN EXISTS (SELECT 1 FROM article) OR EXISTS (SELECT 1 FROM media) THEN 1 ELSE 0 END');

        return $stmt !== false && (int) $stmt->fetchColumn() === 0;
    }

    /**
     * @throws RestoreRefused
     */
    public function fromFile(string $path): RestoreResult
    {
        $size = @filesize($path);
        if (!is_file($path) || $size === false) {
            throw new RestoreRefused("There is no file at $path.");
        }
        if ($size > self::MAX_BYTES) {
            throw new RestoreRefused('That file is too large to be an Edict export.');
        }
        $json = @file_get_contents($path);
        if ($json === false) {
            throw new RestoreRefused("Could not read $path.");
        }

        return $this->fromJson($json);
    }

    /**
     * @throws RestoreRefused
     */
    public function fromJson(string $json): RestoreResult
    {
        if (strlen($json) > self::MAX_BYTES) {
            throw new RestoreRefused('That file is too large to be an Edict export.');
        }
        // Deep enough for the deepest body Body accepts, so that one gets its own message.
        $data = json_decode($json, true, 1024);
        if (!is_array($data) || array_is_list($data)) {
            throw new RestoreRefused('This is not an Edict export: the file is not a JSON object.');
        }

        $version = self::version($data);
        $media = $version === 1 ? [] : $this->media($data['media'] ?? null);
        $articles = $this->articles($data['articles'] ?? null);
        $settings = null;
        if ($version >= 2) {
            $settings = $data['settings'] ?? null;
            if (!is_array($settings)) {
                throw new RestoreRefused('The export has no settings section.');
            }
            $problem = Settings::importProblem($settings);
            if ($problem !== null) {
                throw new RestoreRefused($problem);
            }
        }

        return $this->write($version, $media, $articles, $settings);
    }

    /**
     * @param array<mixed> $data
     */
    private static function version(array $data): int
    {
        if (!array_key_exists('format', $data) && !array_key_exists('version', $data)) {
            // Version 1 had no marker, only these.
            if (is_string($data['exported_at'] ?? null) && is_array($data['articles'] ?? null)) {
                return 1;
            }

            throw new RestoreRefused('This is not an Edict export: it has no format marker and no articles.');
        }
        if (($data['format'] ?? null) !== Export::FORMAT) {
            throw new RestoreRefused('This is not an Edict export: its format is unknown.');
        }
        $version = $data['version'] ?? null;
        if ($version !== 2) {
            $shown = is_int($version) || is_string($version) ? (string) $version : 'missing';

            throw new RestoreRefused("This export has version $shown, which this release of Edict can't read. It reads versions 1 and " . Export::VERSION . '.');
        }

        return $version;
    }

    /**
     * @return list<MediaRow>
     */
    private function media(mixed $list): array
    {
        if (!is_array($list) || !array_is_list($list)) {
            throw new RestoreRefused('The export has no media list.');
        }
        $rows = [];
        $ids = [];
        $hashes = [];
        foreach ($list as $n => $raw) {
            $where = 'Media entry ' . ($n + 1);
            if (!is_array($raw)) {
                throw new RestoreRefused("$where is not an object.");
            }
            $id = self::id($raw['id'] ?? null, $where);
            $where = "Media $id";
            $sha256 = $raw['sha256'] ?? null;
            if (!is_string($sha256) || preg_match('~^[0-9a-f]{64}$~D', $sha256) !== 1) {
                throw new RestoreRefused("$where has no valid SHA-256.");
            }
            $kind = $raw['kind'] ?? null;
            if (!is_string($kind) || !in_array($kind, self::MEDIA_KINDS, true)) {
                throw new RestoreRefused("$where has an unknown kind.");
            }
            $extension = $raw['extension'] ?? null;
            if (!is_string($extension) || preg_match('~^[a-z0-9]{1,16}$~D', $extension) !== 1) {
                throw new RestoreRefused("$where has no valid file extension.");
            }
            $bytes = $raw['bytes'] ?? null;
            if (!is_int($bytes) || $bytes < 0) {
                throw new RestoreRefused("$where has no valid size.");
            }
            $name = $raw['original_name'] ?? null;
            if (!is_string($name)) {
                throw new RestoreRefused("$where has no file name.");
            }
            $poster = $raw['poster_seconds'] ?? null;
            if ($poster !== null && (!(is_int($poster) || is_float($poster)) || !is_finite((float) $poster) || $poster < 0)) {
                throw new RestoreRefused("$where has an invalid poster time.");
            }
            if (isset($ids[$id])) {
                throw new RestoreRefused("Media $id appears twice.");
            }
            if (isset($hashes[$sha256])) {
                throw new RestoreRefused("Two media entries have the same SHA-256 ($sha256).");
            }
            $ids[$id] = true;
            $hashes[$sha256] = true;
            $rows[] = [
                'id' => $id,
                'sha256' => $sha256,
                'kind' => $kind,
                'extension' => $extension,
                'bytes' => $bytes,
                'name' => mb_substr(str_replace("\0", '', $name), 0, self::MAX_NAME),
                'width' => self::dimension($raw['width'] ?? null, $where),
                'height' => self::dimension($raw['height'] ?? null, $where),
                'created' => self::time($raw['created_at'] ?? null, "$where: created_at") ?? $this->clock->now(),
                'poster' => $poster === null ? null : (float) $poster,
            ];
        }

        return $rows;
    }

    /**
     * @return list<ArticleRow>
     */
    private function articles(mixed $list): array
    {
        if (!is_array($list) || !array_is_list($list)) {
            throw new RestoreRefused('The export has no articles list.');
        }
        $rows = [];
        $ids = [];
        $slugs = [];
        foreach ($list as $n => $raw) {
            if (!is_array($raw)) {
                throw new RestoreRefused('Article entry ' . ($n + 1) . ' is not an object.');
            }
            $row = $this->article($raw, 'Article entry ' . ($n + 1));
            if (isset($ids[$row['id']])) {
                throw new RestoreRefused("Article {$row['id']} appears twice.");
            }
            $ids[$row['id']] = true;
            foreach ([$row['slug'], ...$row['oldSlugs']] as $slug) {
                if (isset($slugs[$slug])) {
                    throw new RestoreRefused("The address \"$slug\" is used twice (articles {$slugs[$slug]} and {$row['id']}).");
                }
                $slugs[$slug] = $row['id'];
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @param array<mixed> $raw
     *
     * @return ArticleRow
     */
    private function article(array $raw, string $where): array
    {
        $id = self::id($raw['id'] ?? null, $where);
        $where = "Article $id";

        $kind = self::oneOf($raw['kind'] ?? null, self::KINDS, "$where has an unknown kind.");
        $status = self::oneOf($raw['status'] ?? null, self::STATUSES, "$where has an unknown status.");
        $lang = self::oneOf($raw['lang'] ?? null, ['de', 'en'], "$where has an unknown language.");
        $slug = $raw['slug'] ?? null;
        if (!is_string($slug) || !Slugs::isValid($slug)) {
            throw new RestoreRefused("$where has no valid address (slug).");
        }
        $where = "Article $id (\"$slug\")";
        $title = self::text($raw['title'] ?? null, self::MAX_TITLE, "$where has no valid title.");
        if ($title === '') {
            throw new RestoreRefused("$where has no title.");
        }
        $teaser = self::text($raw['teaser'] ?? '', self::MAX_TEASER, "$where has a teaser that is too long.");
        $coverAlt = self::text($raw['cover_alt'] ?? '', self::MAX_COVER_ALT, "$where has a cover description that is too long.");

        // The same checks as the editor's save. clean() then keeps only what the editor knows.
        $body = $raw['body'] ?? null;
        if (!is_array($body) || ($body['type'] ?? null) !== 'doc') {
            throw new RestoreRefused("$where has no valid body.");
        }
        $problem = Body::problem($body);
        if ($problem !== null) {
            throw new RestoreRefused("$where: $problem");
        }

        $draft = $raw['draft'] ?? null;
        $draftJson = null;
        if ($draft !== null) {
            if (!is_array($draft)) {
                throw new RestoreRefused("$where has an invalid draft.");
            }
            $problem = Body::problem($draft['body'] ?? null);
            if ($problem !== null) {
                throw new RestoreRefused("$where, draft: $problem");
            }
            $draftJson = ArticleInput::fromArray($draft)->toJson();
            if (strlen($draftJson) > ArticleInput::MAX_DRAFT_BYTES) {
                throw new RestoreRefused("$where has a draft that is too long.");
            }
        }

        $tags = [];
        $names = $raw['tags'] ?? [];
        if (!is_array($names) || !array_is_list($names)) {
            throw new RestoreRefused("$where has an invalid tag list.");
        }
        foreach ($names as $name) {
            if (!is_string($name) || str_contains($name, ',')) {
                throw new RestoreRefused("$where has an invalid tag.");
            }
        }
        foreach ((new ArticleInput('', '', $lang, '', Body::EMPTY, implode(',', $names)))->tagList() as $tag) {
            if (mb_strlen($tag['name']) > self::MAX_TAG) {
                throw new RestoreRefused("$where has a tag longer than " . self::MAX_TAG . ' characters.');
            }
            $tags[] = $tag['name'];
        }

        $cover = $raw['cover_media_id'] ?? null;
        if ($cover !== null && (!is_int($cover) || $cover <= 0)) {
            throw new RestoreRefused("$where has an invalid cover.");
        }

        $oldSlugs = $raw['old_slugs'] ?? [];
        if (!is_array($oldSlugs) || !array_is_list($oldSlugs)) {
            throw new RestoreRefused("$where has an invalid list of old addresses.");
        }
        foreach ($oldSlugs as $old) {
            if (!is_string($old) || !Slugs::isValid($old) || $old === $slug) {
                throw new RestoreRefused("$where has an invalid old address.");
            }
        }
        /** @var list<string> $oldSlugs */
        $comments = self::oneOf($raw['comments_mode'] ?? 'site', self::COMMENT_MODES, "$where has an unknown comment setting.");

        $updated = self::time($raw['updated_at'] ?? null, "$where: updated_at") ?? throw new RestoreRefused("$where has no updated_at.");
        $published = self::time($raw['published_at'] ?? null, "$where: published_at");
        $deleted = self::time($raw['deleted_at'] ?? null, "$where: deleted_at");
        if ($deleted === null && ($raw['in_trash'] ?? false) === true) {
            // Version 1 only said that it was in the trash. From now, it has the full time to be restored.
            $deleted = $this->clock->now();
        }

        return [
            'id' => $id,
            'kind' => $kind,
            'status' => $status,
            'slug' => $slug,
            'lang' => $lang,
            'title' => $title,
            'teaser' => $teaser,
            'body' => Body::encode(Body::clean($body)),
            'tags' => $tags,
            'cover' => $cover,
            'coverAlt' => $coverAlt,
            'published' => $published,
            'revised' => self::time($raw['revised_at'] ?? null, "$where: revised_at"),
            'updated' => $updated,
            'created' => self::time($raw['created_at'] ?? null, "$where: created_at") ?? $published ?? $updated,
            'deleted' => $deleted,
            'comments' => $comments,
            'oldSlugs' => $oldSlugs,
            'draft' => $draftJson,
        ];
    }

    /**
     * @param list<MediaRow> $media
     * @param list<ArticleRow> $articles
     * @param array<mixed>|null $settings
     */
    private function write(int $version, array $media, array $articles, ?array $settings): RestoreResult
    {
        [$present, $missing, $notes] = $this->onDisk($media);
        $bytes = array_sum(array_column($present, 'bytes'));
        if ($bytes > $this->config->mediaMaxBytes || count($present) > $this->config->mediaMaxFiles) {
            throw new RestoreRefused('The media in this export exceed the storage limits (MEDIA_MAX_BYTES, MEDIA_MAX_FILES). Ask Ops to raise them first.');
        }
        $restored = [];
        foreach ($present as $row) {
            $restored[$row['id']] = $row['kind'];
        }

        $this->pdo->beginTransaction();
        try {
            // Nobody writes articles or media in between; reading goes on.
            $this->pdo->exec('LOCK TABLE article, media IN EXCLUSIVE MODE');
            if (!$this->isPossible()) {
                throw new RestoreRefused('This install already has articles, pages or media. A restore only goes into an empty install, such as one set up again after losing its database, so that nothing here is overwritten.');
            }

            $this->insertMedia($present);
            [$tags, $referenced] = $this->insertArticles($articles, $restored);

            $settingsRestored = false;
            if ($settings !== null) {
                foreach (['logo', 'favicon'] as $image) {
                    if (is_int($settings[$image] ?? null) && ($restored[$settings[$image]] ?? null) !== 'image') {
                        $settings[$image] = 0;
                    }
                }
                $this->settings->import($settings);
                $settingsRestored = true;
                $favicon = $settings['favicon'] ?? null;
                if (is_int($favicon) && $favicon > 0) {
                    $this->jobs->enqueue(IconProcessor::JOB, ['media_id' => $favicon]);
                }
            }

            // Ids that went missing stay unused, so a new upload never takes the place
            // of a picture an article still points at.
            $this->resetSequence('article', max([0, ...array_column($articles, 'id')]));
            $this->resetSequence('media', max([0, ...array_column($media, 'id'), ...$referenced]));
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            if ($e instanceof \PDOException) {
                throw new RestoreRefused('The database refused the export, so nothing was restored: ' . $e->getMessage(), 0, $e);
            }

            throw $e;
        }

        $lost = array_values(array_filter($referenced, static fn(int $id): bool => !isset($restored[$id])));
        if ($version === 1) {
            $notes[] = 'This is a version 1 export, without media or settings. '
                . ($lost === [] ? 'Its articles use no media.' : count($lost) . ' media ' . (count($lost) === 1 ? 'reference' : 'references') . ' in the articles (images, players, files, covers) can\'t be relinked; covers were removed. Upload those files again and put them back into the articles.');
        }

        return new RestoreResult(
            $version,
            count(array_filter($articles, static fn(array $row): bool => $row['kind'] === 'article')),
            count(array_filter($articles, static fn(array $row): bool => $row['kind'] === 'page')),
            $tags,
            count($present),
            $missing,
            $settingsRestored,
            $notes,
        );
    }

    /**
     * @param list<MediaRow> $media
     *
     * @return array{list<MediaRow>, list<string>, list<string>} the rows with their original on disk, the missing ones, and notes
     */
    private function onDisk(array $media): array
    {
        $present = [];
        $missing = [];
        $notes = [];
        foreach ($media as $row) {
            try {
                $found = is_file($this->store->originalPath($row['sha256']));
            } catch (MediaError) {
                $found = false;
                $notes = ['Media storage (DATA_DIR) is not set up, so no media file could be found.'];
            }
            if ($found) {
                $present[] = $row;
            } else {
                $missing[] = $row['name'] . ' (' . substr($row['sha256'], 0, 12) . ')';
            }
        }

        return [$present, $missing, $notes];
    }

    /**
     * Rows as "processing", with the job an upload gets, so the worker makes the
     * variants again from the original.
     *
     * @param list<MediaRow> $media
     */
    private function insertMedia(array $media): void
    {
        $insert = $this->pdo->prepare(
            "INSERT INTO media (id, sha256, kind, extension, bytes, original_name, width, height, status, meta, created_at)
             OVERRIDING SYSTEM VALUE VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'processing', ?::jsonb, to_timestamp(?))",
        );
        foreach ($media as $row) {
            $meta = $row['kind'] === 'video' && $row['poster'] !== null ? ['restore_poster' => $row['poster']] : [];
            $insert->execute([
                $row['id'], $row['sha256'], $row['kind'], $row['extension'], $row['bytes'], $row['name'],
                $row['width'], $row['height'], json_encode((object) $meta, JSON_THROW_ON_ERROR), $row['created'],
            ]);
            $this->jobs->enqueue(MediaLibrary::jobFor($row['kind']), ['media_id' => $row['id']]);
        }
    }

    /**
     * @param list<ArticleRow> $articles
     * @param array<int, string> $restored the restored media's kinds, by id
     *
     * @return array{int, list<int>} how many tags, and every media id the articles use
     */
    private function insertArticles(array $articles, array $restored): array
    {
        $insert = $this->pdo->prepare(
            'INSERT INTO article (id, kind, slug, lang, title, teaser, body_json, body_text, status, draft, cover_media_id, cover_alt,
                                  published_at, revised_at, created_at, updated_at, deleted_at, comments_mode)
             OVERRIDING SYSTEM VALUE VALUES (?, ?, ?, ?, ?, ?, ?::jsonb, edict_body_text(?::jsonb), ?, ?::jsonb, ?, ?,
                                  to_timestamp(?), to_timestamp(?), to_timestamp(?), to_timestamp(?), to_timestamp(?), ?)',
        );
        $tag = $this->pdo->prepare('INSERT INTO tag (slug, name) VALUES (?, ?) ON CONFLICT (slug) DO UPDATE SET slug = EXCLUDED.slug RETURNING id');
        $link = $this->pdo->prepare('INSERT INTO article_tag (article_id, tag_id) VALUES (?, ?) ON CONFLICT DO NOTHING');
        $redirect = $this->pdo->prepare('INSERT INTO slug_redirect (old_slug, article_id) VALUES (?, ?)');

        $tagSlugs = [];
        $referenced = [];
        foreach ($articles as $row) {
            $cover = $row['cover'] !== null && ($restored[$row['cover']] ?? null) === 'image' ? $row['cover'] : null;
            $insert->execute([
                $row['id'], $row['kind'], $row['slug'], $row['lang'], $row['title'], $row['teaser'], $row['body'], $row['body'],
                $row['status'], $row['draft'], $cover, $row['coverAlt'],
                $row['published'], $row['revised'], $row['created'], $row['updated'], $row['deleted'], $row['comments'],
            ]);
            foreach ($row['tags'] as $name) {
                $slug = Slugs::fromText($name);
                $tag->execute([$slug, $name]);
                $link->execute([$row['id'], $tag->fetchColumn()]);
                $tagSlugs[$slug] = true;
            }
            foreach ($row['oldSlugs'] as $old) {
                $redirect->execute([$old, $row['id']]);
            }

            $referenced = [...$referenced, ...Body::mediaIds(Body::decode($row['body']))];
            if ($row['cover'] !== null) {
                $referenced[] = $row['cover'];
            }
            if ($row['draft'] !== null) {
                $draft = ArticleInput::fromJson($row['draft']);
                $referenced = [...$referenced, ...Body::mediaIds(Body::decode($draft->body))];
                if ($draft->cover !== null) {
                    $referenced[] = $draft->cover;
                }
            }
        }

        return [count($tagSlugs), array_values(array_unique($referenced))];
    }

    private function resetSequence(string $table, int $max): void
    {
        $this->pdo->prepare('SELECT setval(pg_get_serial_sequence(?, \'id\'), ?, ?::boolean)')
            ->execute([$table, max($max, 1), $max > 0 ? 'true' : 'false']);
    }

    private static function id(mixed $value, string $where): int
    {
        if (!is_int($value) || $value <= 0) {
            throw new RestoreRefused("$where has no valid id.");
        }

        return $value;
    }

    private static function dimension(mixed $value, string $where): ?int
    {
        if ($value !== null && (!is_int($value) || $value < 0)) {
            throw new RestoreRefused("$where has an invalid width or height.");
        }

        return $value;
    }

    /**
     * @param list<string> $allowed
     */
    private static function oneOf(mixed $value, array $allowed, string $message): string
    {
        if (!is_string($value) || !in_array($value, $allowed, true)) {
            throw new RestoreRefused($message);
        }

        return $value;
    }

    private static function text(mixed $value, int $max, string $message): string
    {
        if (!is_string($value) || mb_strlen($value) > $max) {
            throw new RestoreRefused($message);
        }

        return str_replace("\0", '', $value);
    }

    private static function time(mixed $value, string $what): ?int
    {
        if ($value === null) {
            return null;
        }
        $time = is_string($value) ? \DateTimeImmutable::createFromFormat(DATE_ATOM, $value) : false;
        if ($time === false) {
            throw new RestoreRefused("$what is not a valid date.");
        }

        return $time->getTimestamp();
    }
}
