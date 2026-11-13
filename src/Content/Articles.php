<?php

declare(strict_types=1);

namespace Edict\Content;

use Edict\Clock;
use Edict\Database\Row;

/**
 * Article storage. "Public" means published, out of the trash and with a publish
 * date that has passed; every public read goes through PUBLIC_WHERE. Listings add
 * LISTED, so static pages are reachable but never appear in them.
 */
final readonly class Articles
{
    public const TRASH_DAYS = 30;
    public const KEEP_REVISIONS = 20;
    private const PLACEHOLDER_TITLE = 'Untitled';

    private const COLUMNS = "a.id, a.kind, a.slug, a.lang, a.title, a.teaser, a.body_json::text AS body_json, a.status,
        a.draft::text AS draft, a.draft_version, a.cover_media_id, a.cover_alt,
        EXTRACT(EPOCH FROM a.published_at)::bigint AS published_at,
        EXTRACT(EPOCH FROM a.updated_at)::bigint AS updated_at,
        EXTRACT(EPOCH FROM a.deleted_at)::bigint AS deleted_at,
        EXTRACT(EPOCH FROM a.revised_at)::bigint AS revised_at,
        (SELECT coalesce(json_agg(json_build_object('slug', t.slug, 'name', t.name) ORDER BY t.name), '[]')::text
         FROM article_tag at JOIN tag t ON t.id = at.tag_id WHERE at.article_id = a.id) AS tags";

    private const PUBLIC_WHERE = "a.status = 'published' AND a.deleted_at IS NULL AND a.published_at <= to_timestamp(?)";

    private const LISTED = "a.kind = 'article' AND ";

    public function __construct(private \PDO $pdo, private Clock $clock) {}

    /**
     * A blank row for the editor. Its slug is a placeholder until the first publish,
     * and the blank draft keeps the placeholders out of the form.
     */
    public function createEmpty(string $kind): int
    {
        return $this->transaction(function () use ($kind): int {
            $now = $this->clock->now();
            $lang = $this->lastLanguage();
            $placeholder = new ArticleInput(self::PLACEHOLDER_TITLE, '', $lang, '', Body::EMPTY, '');
            $blank = new ArticleInput('', '', $lang, '', Body::EMPTY, '');

            $stmt = $this->pdo->prepare(
                'INSERT INTO article (kind, slug, lang, title, body_json, draft, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, to_timestamp(?), to_timestamp(?)) RETURNING id',
            );
            $stmt->execute([$kind, $this->slugFor($placeholder, null), $lang, self::PLACEHOLDER_TITLE, Body::EMPTY, $blank->toJson(), $now, $now]);

            return Row::int(['id' => $stmt->fetchColumn()], 'id');
        });
    }

    /**
     * Autosave. Never touches the live columns.
     *
     * @return int|null the new version, or null if another editor saved in between or the article is in the trash
     */
    public function saveDraft(int $id, int $version, string $writer, string $draftJson): ?int
    {
        $stmt = $this->pdo->prepare(
            'UPDATE article SET draft = ?, draft_version = draft_version + 1, draft_writer = ?, updated_at = to_timestamp(?)
             WHERE id = ? AND (draft_version = ? OR draft_writer = ?) AND deleted_at IS NULL RETURNING draft_version',
        );
        $stmt->execute([$draftJson, $writer, $this->clock->now(), $id, $version, $writer]);
        $saved = $stmt->fetchColumn();

        return $saved === false ? null : Row::int(['v' => $saved], 'v');
    }

    /**
     * Makes this version live and clears the draft. An empty slug means: derive one
     * if the article was never public, and keep its address if it was. If a published
     * article changes its slug, the old address keeps working through a redirect.
     *
     * The draft is only cleared if it is still the version that was read, so text
     * autosaved a moment ago can't vanish.
     *
     * @throws SlugTaken
     * @throws StaleDraft
     */
    public function publish(Article $article, ArticleInput $input): void
    {
        $this->transaction(function () use ($article, $input): void {
            $slug = $input->slug === '' && $article->publishedAt !== null ? $article->slug : $this->slugFor($input, $article->id);

            if ($slug !== $article->slug) {
                $this->pdo->prepare('DELETE FROM slug_redirect WHERE old_slug = ?')->execute([$slug]);
                if ($article->publishedAt !== null) {
                    $this->pdo->prepare('INSERT INTO slug_redirect (old_slug, article_id) VALUES (?, ?)')
                        ->execute([$article->slug, $article->id]);
                }
            }

            $now = $this->clock->now();
            // Changed after its first publish: readers see "Updated on". The same text again isn't a change.
            $revised = $article->publishedAt !== null && $article->liveInput()->toJson() !== $input->toJson();
            $update = $this->pdo->prepare(
                // A cover that isn't an image, or no longer exists, is simply none.
                "UPDATE article SET slug = ?, lang = ?, title = ?, teaser = ?, body_json = ?, status = 'published',
                        cover_media_id = (SELECT id FROM media WHERE id = ? AND kind = 'image'), cover_alt = ?,
                        published_at = coalesce(published_at, to_timestamp(?)), updated_at = to_timestamp(?),
                        revised_at = CASE WHEN ?::boolean THEN to_timestamp(?) ELSE revised_at END,
                        draft = NULL, draft_version = draft_version + 1, draft_writer = NULL
                 WHERE id = ? AND draft_version = ? AND deleted_at IS NULL",
            );
            $update->execute([
                $slug, $input->lang, $input->title, $input->teaser, $input->body, $input->cover, $input->coverAlt,
                $now, $now, $revised ? 'true' : 'false', $now, $article->id, $article->draftVersion,
            ]);
            if ($update->rowCount() !== 1) {
                throw new StaleDraft();
            }

            $this->setTags($article->id, $input);
            $this->keepRevision($article->id, $input, $now);
        });
    }

    /**
     * The published versions, newest first.
     *
     * @return list<array{id: int, publishedAt: int, title: string}>
     */
    public function revisions(int $articleId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT id, EXTRACT(EPOCH FROM published_at)::bigint AS published_at, content->>'title' AS title
             FROM article_revision WHERE article_id = ? ORDER BY id DESC",
        );
        $stmt->execute([$articleId]);
        $revisions = [];
        foreach ($stmt->fetchAll() as $row) {
            if (is_array($row)) {
                $revisions[] = ['id' => Row::int($row, 'id'), 'publishedAt' => Row::int($row, 'published_at'), 'title' => is_string($row['title'] ?? null) ? $row['title'] : ''];
            }
        }

        return $revisions;
    }

    /**
     * Puts a published version into the draft, to be checked and published again.
     * The live article stays as it is until then.
     *
     * @return bool false if there is no such version, or the article is in the trash
     */
    public function restoreRevision(int $articleId, int $revisionId): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE article a SET draft = r.content, draft_version = a.draft_version + 1, draft_writer = NULL, updated_at = to_timestamp(?)
             FROM article_revision r WHERE r.id = ? AND r.article_id = a.id AND a.id = ? AND a.deleted_at IS NULL',
        );
        $stmt->execute([$this->clock->now(), $revisionId, $articleId]);

        return $stmt->rowCount() === 1;
    }

    /**
     * Everything written, for the JSON export: what readers see, the draft, and the tags.
     *
     * @return array{exported_at: string, articles: list<array<string, mixed>>, tags: list<array{slug: string, name: string}>}
     */
    public function export(): array
    {
        $articles = [];
        $stmt = $this->pdo->query('SELECT ' . self::COLUMNS . ' FROM article a ORDER BY a.id');
        foreach ($stmt === false ? [] : $stmt->fetchAll() as $row) {
            if (!is_array($row)) {
                continue;
            }
            $article = self::hydrate($row);
            $articles[] = [
                'id' => $article->id,
                'kind' => $article->kind,
                'status' => $article->status,
                'slug' => $article->slug,
                'lang' => $article->lang,
                'title' => $article->title,
                'teaser' => $article->teaser,
                'body' => Body::decode($article->bodyJson),
                'tags' => array_column($article->tags, 'name'),
                'cover_media_id' => $article->coverMediaId,
                'cover_alt' => $article->coverAlt,
                'published_at' => $article->publishedAt === null ? null : gmdate(DATE_ATOM, $article->publishedAt),
                'revised_at' => $article->revisedAt === null ? null : gmdate(DATE_ATOM, $article->revisedAt),
                'updated_at' => gmdate(DATE_ATOM, $article->updatedAt),
                'in_trash' => $article->deletedAt !== null,
                'draft' => $article->draftJson === null ? null : json_decode($article->draftJson, true),
            ];
        }
        $tags = [];
        $stmt = $this->pdo->query('SELECT slug, name FROM tag ORDER BY name');
        foreach ($stmt === false ? [] : $stmt->fetchAll() as $row) {
            if (is_array($row)) {
                $tags[] = ['slug' => Row::str($row, 'slug'), 'name' => Row::str($row, 'name')];
            }
        }

        return ['exported_at' => gmdate(DATE_ATOM, $this->clock->now()), 'articles' => $articles, 'tags' => $tags];
    }

    private function keepRevision(int $articleId, ArticleInput $input, int $now): void
    {
        $this->pdo->prepare('INSERT INTO article_revision (article_id, content, published_at) VALUES (?, ?::jsonb, to_timestamp(?))')
            ->execute([$articleId, $input->toJson(), $now]);
        $this->pdo->prepare(
            'DELETE FROM article_revision WHERE article_id = ? AND id NOT IN (
                 SELECT id FROM article_revision WHERE article_id = ? ORDER BY id DESC LIMIT ?
             )',
        )->execute([$articleId, $articleId, self::KEEP_REVISIONS]);
    }

    public function unpublish(int $id): void
    {
        $this->pdo->prepare(
            "UPDATE article SET status = 'unpublished', updated_at = to_timestamp(?) WHERE id = ? AND status = 'published'",
        )->execute([$this->clock->now(), $id]);
    }

    public function trash(int $id): void
    {
        $this->pdo->prepare('UPDATE article SET deleted_at = to_timestamp(?) WHERE id = ? AND deleted_at IS NULL')
            ->execute([$this->clock->now(), $id]);
    }

    public function restore(int $id): void
    {
        $this->pdo->prepare('UPDATE article SET deleted_at = NULL WHERE id = ?')->execute([$id]);
    }

    /**
     * Permanent. Only articles already in the trash can go.
     */
    public function delete(int $id): void
    {
        $this->pdo->prepare('DELETE FROM article WHERE id = ? AND deleted_at IS NOT NULL')->execute([$id]);
        $this->removeOrphanTags();
    }

    public function purgeTrash(): void
    {
        $this->pdo->prepare('DELETE FROM article WHERE deleted_at <= to_timestamp(?)')
            ->execute([$this->clock->now() - self::TRASH_DAYS * 86400]);
        $this->removeOrphanTags();
    }

    public function find(int $id): ?Article
    {
        $stmt = $this->pdo->prepare('SELECT ' . self::COLUMNS . ' FROM article a WHERE a.id = ?');
        $stmt->execute([$id]);

        return self::one($stmt);
    }

    public function findPublic(string $slug): ?Article
    {
        $stmt = $this->pdo->prepare('SELECT ' . self::COLUMNS . ' FROM article a WHERE a.slug = ? AND ' . self::PUBLIC_WHERE);
        $stmt->execute([$slug, $this->clock->now()]);

        return self::one($stmt);
    }

    /**
     * @return string|null the current slug, if this is an old slug of a public article
     */
    public function redirectTarget(string $oldSlug): ?string
    {
        $stmt = $this->pdo->prepare(
            'SELECT a.slug FROM slug_redirect r JOIN article a ON a.id = r.article_id WHERE r.old_slug = ? AND ' . self::PUBLIC_WHERE,
        );
        $stmt->execute([$oldSlug, $this->clock->now()]);
        $slug = $stmt->fetchColumn();

        return is_string($slug) ? $slug : null;
    }

    /**
     * @return list<Article>
     */
    public function publicList(int $limit, int $offset): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::COLUMNS . ' FROM article a WHERE ' . self::LISTED . self::PUBLIC_WHERE . ' ORDER BY a.published_at DESC, a.id DESC LIMIT ? OFFSET ?',
        );
        $stmt->execute([$this->clock->now(), $limit, $offset]);

        return self::all($stmt);
    }

    /**
     * Static pages readers can open, for choosing the imprint and privacy pages.
     *
     * @return list<Article>
     */
    public function publishedPages(): array
    {
        $stmt = $this->pdo->prepare('SELECT ' . self::COLUMNS . " FROM article a WHERE a.kind = 'page' AND " . self::PUBLIC_WHERE . ' ORDER BY a.title');
        $stmt->execute([$this->clock->now()]);

        return self::all($stmt);
    }

    public function publicCount(): int
    {
        $stmt = $this->pdo->prepare('SELECT count(*) FROM article a WHERE ' . self::LISTED . self::PUBLIC_WHERE);
        $stmt->execute([$this->clock->now()]);

        return Row::int(['n' => $stmt->fetchColumn()], 'n');
    }

    /**
     * @return list<Article>
     */
    public function publicListByTag(string $tagSlug, int $limit, int $offset): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::COLUMNS . ' FROM article a
             JOIN article_tag x ON x.article_id = a.id JOIN tag y ON y.id = x.tag_id
             WHERE y.slug = ? AND ' . self::LISTED . self::PUBLIC_WHERE . ' ORDER BY a.published_at DESC, a.id DESC LIMIT ? OFFSET ?',
        );
        $stmt->execute([$tagSlug, $this->clock->now(), $limit, $offset]);

        return self::all($stmt);
    }

    public function publicCountByTag(string $tagSlug): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT count(*) FROM article a JOIN article_tag x ON x.article_id = a.id JOIN tag y ON y.id = x.tag_id
             WHERE y.slug = ? AND ' . self::LISTED . self::PUBLIC_WHERE,
        );
        $stmt->execute([$tagSlug, $this->clock->now()]);

        return Row::int(['n' => $stmt->fetchColumn()], 'n');
    }

    public function tagName(string $slug): ?string
    {
        $stmt = $this->pdo->prepare('SELECT name FROM tag WHERE slug = ?');
        $stmt->execute([$slug]);
        $name = $stmt->fetchColumn();

        return is_string($name) ? $name : null;
    }

    /**
     * @return list<Article> for the admin list, most recently changed first
     */
    public function adminList(string $kind, bool $trash): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::COLUMNS . ' FROM article a WHERE a.kind = ? AND a.deleted_at IS ' . ($trash ? 'NOT NULL' : 'NULL') . ' ORDER BY a.updated_at DESC, a.id DESC',
        );
        $stmt->execute([$kind]);

        return self::all($stmt);
    }

    public function lastLanguage(): string
    {
        $stmt = $this->pdo->query('SELECT lang FROM article ORDER BY created_at DESC, id DESC LIMIT 1');
        $lang = $stmt === false ? false : $stmt->fetchColumn();

        return is_string($lang) ? $lang : 'de';
    }

    /**
     * An explicit slug must be free. Otherwise one is derived from the title, with a
     * number added if needed. Old slugs of other articles count as taken, because
     * links to them are still out there.
     *
     * @throws SlugTaken
     */
    private function slugFor(ArticleInput $input, ?int $articleId): string
    {
        if ($input->slug !== '') {
            if (!$this->slugIsFree($input->slug, $articleId)) {
                throw new SlugTaken($input->slug);
            }

            return $input->slug;
        }

        $base = Slugs::fromText($input->title);
        if ($base === '' || Slugs::isReserved($base)) {
            $base = trim($base . '-article', '-');
        }

        $slug = $base;
        for ($n = 2; !$this->slugIsFree($slug, $articleId); ++$n) {
            $suffix = '-' . $n;
            $slug = rtrim(substr($base, 0, Slugs::MAX_LENGTH - strlen($suffix)), '-') . $suffix;
        }

        return $slug;
    }

    private function slugIsFree(string $slug, ?int $articleId): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM article WHERE slug = ? AND id IS DISTINCT FROM ?
             UNION ALL
             SELECT 1 FROM slug_redirect WHERE old_slug = ? AND article_id IS DISTINCT FROM ?',
        );
        $stmt->execute([$slug, $articleId, $slug, $articleId]);

        return $stmt->fetchColumn() === false;
    }

    private function setTags(int $articleId, ArticleInput $input): void
    {
        $this->pdo->prepare('DELETE FROM article_tag WHERE article_id = ?')->execute([$articleId]);

        $upsert = $this->pdo->prepare(
            'INSERT INTO tag (slug, name) VALUES (?, ?) ON CONFLICT (slug) DO UPDATE SET slug = EXCLUDED.slug RETURNING id',
        );
        $link = $this->pdo->prepare('INSERT INTO article_tag (article_id, tag_id) VALUES (?, ?)');
        foreach ($input->tagList() as $tag) {
            $upsert->execute([$tag['slug'], $tag['name']]);
            $link->execute([$articleId, $upsert->fetchColumn()]);
        }

        $this->removeOrphanTags();
    }

    private function removeOrphanTags(): void
    {
        $this->pdo->exec('DELETE FROM tag t WHERE NOT EXISTS (SELECT 1 FROM article_tag x WHERE x.tag_id = t.id)');
    }

    private static function one(\PDOStatement $stmt): ?Article
    {
        $row = $stmt->fetch();

        return is_array($row) ? self::hydrate($row) : null;
    }

    /**
     * @return list<Article>
     */
    private static function all(\PDOStatement $stmt): array
    {
        $articles = [];
        foreach ($stmt->fetchAll() as $row) {
            if (is_array($row)) {
                $articles[] = self::hydrate($row);
            }
        }

        return $articles;
    }

    /**
     * @param array<mixed> $row
     */
    private static function hydrate(array $row): Article
    {
        $tags = [];
        $decoded = json_decode(Row::str($row, 'tags'), true);
        foreach (is_array($decoded) ? $decoded : [] as $tag) {
            if (is_array($tag)) {
                $tags[] = ['slug' => Row::str($tag, 'slug'), 'name' => Row::str($tag, 'name')];
            }
        }

        $draft = $row['draft'] ?? null;

        return new Article(
            Row::int($row, 'id'),
            Row::str($row, 'kind'),
            Row::str($row, 'slug'),
            Row::str($row, 'lang'),
            Row::str($row, 'title'),
            Row::str($row, 'teaser'),
            Row::str($row, 'body_json'),
            Row::str($row, 'status'),
            Row::intOrNull($row, 'published_at'),
            Row::int($row, 'updated_at'),
            Row::intOrNull($row, 'deleted_at'),
            $tags,
            is_string($draft) ? $draft : null,
            Row::int($row, 'draft_version'),
            Row::intOrNull($row, 'cover_media_id'),
            Row::str($row, 'cover_alt'),
            Row::intOrNull($row, 'revised_at'),
        );
    }

    /**
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    private function transaction(callable $work): mixed
    {
        $this->pdo->beginTransaction();
        try {
            $result = $work();
            $this->pdo->commit();

            return $result;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();

            if ($e instanceof \PDOException && $e->getCode() === '23505') {
                // Another request took the slug between the check and the write.
                throw new SlugTaken('Slug already taken', 0, $e);
            }

            throw $e;
        }
    }
}
