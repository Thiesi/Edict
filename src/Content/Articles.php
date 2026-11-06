<?php

declare(strict_types=1);

namespace Edict\Content;

use Edict\Clock;
use Edict\Database\Row;

/**
 * Article storage. "Public" means published, out of the trash and with a publish
 * date that has passed; every public read goes through PUBLIC_WHERE.
 */
final readonly class Articles
{
    public const TRASH_DAYS = 30;

    private const COLUMNS = "a.id, a.slug, a.lang, a.title, a.teaser, a.body_json::text AS body_json, a.status,
        EXTRACT(EPOCH FROM a.published_at)::bigint AS published_at,
        EXTRACT(EPOCH FROM a.updated_at)::bigint AS updated_at,
        EXTRACT(EPOCH FROM a.deleted_at)::bigint AS deleted_at,
        (SELECT coalesce(json_agg(json_build_object('slug', t.slug, 'name', t.name) ORDER BY t.name), '[]')::text
         FROM article_tag at JOIN tag t ON t.id = at.tag_id WHERE at.article_id = a.id) AS tags";

    private const PUBLIC_WHERE = "a.status = 'published' AND a.deleted_at IS NULL AND a.published_at <= to_timestamp(?)";

    public function __construct(private \PDO $pdo, private Clock $clock) {}

    /**
     * @throws SlugTaken
     */
    public function create(ArticleInput $input): int
    {
        return $this->transaction(function () use ($input): int {
            $now = $this->clock->now();
            $stmt = $this->pdo->prepare(
                'INSERT INTO article (slug, lang, title, teaser, body_json, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, to_timestamp(?), to_timestamp(?)) RETURNING id',
            );
            $stmt->execute([
                $this->slugFor($input, null),
                $input->lang,
                $input->title,
                $input->teaser,
                Body::fromPlainText($input->body),
                $now,
                $now,
            ]);
            $id = Row::int(['id' => $stmt->fetchColumn()], 'id');
            $this->setTags($id, $input);

            return $id;
        });
    }

    /**
     * An empty slug keeps the current one. If a published article changes its slug,
     * the old address keeps working through a redirect.
     *
     * @throws SlugTaken
     */
    public function update(Article $article, ArticleInput $input): void
    {
        $this->transaction(function () use ($article, $input): void {
            $slug = $input->slug === '' ? $article->slug : $this->slugFor($input, $article->id);

            if ($slug !== $article->slug) {
                $this->pdo->prepare('DELETE FROM slug_redirect WHERE old_slug = ?')->execute([$slug]);
                if ($article->publishedAt !== null) {
                    $this->pdo->prepare('INSERT INTO slug_redirect (old_slug, article_id) VALUES (?, ?)')
                        ->execute([$article->slug, $article->id]);
                }
            }

            $this->pdo->prepare(
                'UPDATE article SET slug = ?, lang = ?, title = ?, teaser = ?, body_json = ?, updated_at = to_timestamp(?) WHERE id = ?',
            )->execute([$slug, $input->lang, $input->title, $input->teaser, Body::fromPlainText($input->body), $this->clock->now(), $article->id]);

            $this->setTags($article->id, $input);
        });
    }

    public function publish(int $id): void
    {
        $now = $this->clock->now();
        $this->pdo->prepare(
            "UPDATE article SET status = 'published', published_at = coalesce(published_at, to_timestamp(?)), updated_at = to_timestamp(?)
             WHERE id = ? AND deleted_at IS NULL",
        )->execute([$now, $now, $id]);
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
            'SELECT ' . self::COLUMNS . ' FROM article a WHERE ' . self::PUBLIC_WHERE . ' ORDER BY a.published_at DESC, a.id DESC LIMIT ? OFFSET ?',
        );
        $stmt->execute([$this->clock->now(), $limit, $offset]);

        return self::all($stmt);
    }

    public function publicCount(): int
    {
        $stmt = $this->pdo->prepare('SELECT count(*) FROM article a WHERE ' . self::PUBLIC_WHERE);
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
             WHERE y.slug = ? AND ' . self::PUBLIC_WHERE . ' ORDER BY a.published_at DESC, a.id DESC LIMIT ? OFFSET ?',
        );
        $stmt->execute([$tagSlug, $this->clock->now(), $limit, $offset]);

        return self::all($stmt);
    }

    public function publicCountByTag(string $tagSlug): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT count(*) FROM article a JOIN article_tag x ON x.article_id = a.id JOIN tag y ON y.id = x.tag_id
             WHERE y.slug = ? AND ' . self::PUBLIC_WHERE,
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
    public function adminList(bool $trash): array
    {
        $stmt = $this->pdo->query(
            'SELECT ' . self::COLUMNS . ' FROM article a WHERE a.deleted_at IS ' . ($trash ? 'NOT NULL' : 'NULL') . ' ORDER BY a.updated_at DESC, a.id DESC',
        );

        return $stmt === false ? [] : self::all($stmt);
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

        return new Article(
            Row::int($row, 'id'),
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
