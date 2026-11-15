<?php

declare(strict_types=1);

namespace Edict\Tests\Media;

use Edict\Auth\AdminAccount;
use Edict\Auth\SessionStore;
use Edict\Content\Articles;
use Edict\Media\JobQueue;
use Edict\Media\MediaInventory;
use Edict\Media\MediaLibrary;
use Edict\Media\MediaStore;
use Edict\Tests\DbTestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * The Media page: the list, where each item is used, and deleting what nobody uses.
 */
final class MediaInventoryFlowTest extends DbTestCase
{
    private const SESSION = 'edict_session';

    private string $dataDir;

    /** @var array<string, string> */
    private array $settings = [];

    /** @var array<string, string> */
    private array $admin;
    private string $csrf;

    protected function setUp(): void
    {
        $this->dataDir = sys_get_temp_dir() . '/edict-inventory-test-' . bin2hex(random_bytes(6));
        mkdir($this->dataDir);
        parent::setUp();
        $this->signIn();
    }

    protected function tearDown(): void
    {
        MediaStore::remove($this->dataDir);
        parent::tearDown();
    }

    protected function extraEnv(): array
    {
        return $this->settings + ['DATA_DIR' => $this->dataDir, 'MEDIA_MIN_FREE_BYTES' => '0'];
    }

    private function signIn(): void
    {
        $this->service(AdminAccount::class)->create('sovereign', 'correct horse battery');
        $id = $this->pdo->query('SELECT id FROM app_user WHERE is_admin');
        self::assertNotFalse($id);
        $sessions = $this->service(SessionStore::class);
        $token = $sessions->create((int) $id->fetchColumn(), 365 * 86400);
        $session = $sessions->load($token);
        self::assertNotNull($session);
        $this->admin = [self::SESSION => $token];
        $this->csrf = $session->csrfToken;
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function adminPost(string $path, array $fields = []): ResponseInterface
    {
        return $this->post($path, $fields + ['_csrf' => $this->csrf], $this->admin);
    }

    /**
     * A stored item with its files on disk, as the worker leaves it.
     *
     * @param list<array{string, string}> $variants preset and format
     */
    private function media(string $name, string $kind = 'image', array $variants = [['w480-webp', 'webp'], ['w480-jpg', 'jpg'], ['full', 'jpg']], string $status = 'ready'): int
    {
        $sha = hash('sha256', $name . random_bytes(8));
        $stmt = $this->pdo->prepare(
            "INSERT INTO media (sha256, kind, extension, bytes, original_name, width, height, status, created_at)
             VALUES (?, ?, 'bin', 1000, ?, 800, 600, ?, to_timestamp(?)) RETURNING id",
        );
        $stmt->execute([$sha, $kind, $name, $status, $this->clock->now()]);
        $id = (int) $stmt->fetchColumn();
        $this->clock->advance(60);

        $store = $this->service(MediaStore::class);
        $original = $store->originalPath($sha);
        @mkdir(dirname($original), 0o700, true);
        file_put_contents($original, str_repeat('o', 1000));
        $insert = $this->pdo->prepare('INSERT INTO media_variant (media_id, preset, format, width, height, bytes) VALUES (?, ?, ?, 480, 360, 100)');
        foreach ($variants as [$preset, $format]) {
            $insert->execute([$id, $preset, $format]);
            $path = $store->variantPath($sha, $preset, $format);
            @mkdir(dirname($path), 0o755, true);
            file_put_contents($path, str_repeat('v', 100));
        }

        return $id;
    }

    private function sha(int $mediaId): string
    {
        $stmt = $this->pdo->prepare('SELECT sha256 FROM media WHERE id = ?');
        $stmt->execute([$mediaId]);

        return (string) $stmt->fetchColumn();
    }

    /**
     * @param list<array<string, mixed>> $blocks
     */
    private static function doc(array $blocks): string
    {
        return (string) json_encode(['type' => 'doc', 'content' => $blocks]);
    }

    /**
     * A document as the editor posts it: arrays, as parsed JSON arrives.
     *
     * @return array<mixed>
     */
    private static function tree(string $json): array
    {
        $tree = json_decode($json, true);
        self::assertIsArray($tree);

        return $tree;
    }

    /**
     * @return array<string, mixed>
     */
    private static function image(int $mediaId): array
    {
        return ['type' => 'image', 'attrs' => ['mediaId' => $mediaId, 'alt' => '', 'caption' => '']];
    }

    /**
     * An article straight in the database.
     *
     * @param array{title?: string, kind?: string, body?: string, draft?: array<string, mixed>|null, cover?: int|null, trashed?: bool} $fields
     */
    private function article(array $fields): int
    {
        static $n = 0;
        ++$n;
        $stmt = $this->pdo->prepare(
            "INSERT INTO article (slug, lang, title, body_json, status, published_at, created_at, updated_at, kind, draft, cover_media_id, deleted_at)
             VALUES (?, 'en', ?, ?::jsonb, 'published', to_timestamp(?), to_timestamp(?), to_timestamp(?), ?, ?::jsonb, ?, ?) RETURNING id",
        );
        $now = $this->clock->now();
        $draft = $fields['draft'] ?? null;
        $stmt->execute([
            "piece-$n",
            $fields['title'] ?? "Piece $n",
            $fields['body'] ?? self::doc([]),
            $now,
            $now,
            $now,
            $fields['kind'] ?? 'article',
            $draft === null ? null : json_encode($draft),
            $fields['cover'] ?? null,
            ($fields['trashed'] ?? false) ? date('c', $now) : null,
        ]);

        return (int) $stmt->fetchColumn();
    }

    private function setting(string $key, int $value): void
    {
        $this->pdo->prepare('INSERT INTO setting (key, value) VALUES (?, ?::jsonb)')->execute([$key, json_encode($value)]);
    }

    private function rows(string $table, string $where = 'true'): int
    {
        $stmt = $this->pdo->query("SELECT count(*) FROM $table WHERE $where");
        self::assertNotFalse($stmt);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @return list<string> every file under the data directory, relative to it
     */
    private function files(): array
    {
        $files = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dataDir, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile()) {
                $files[] = str_replace('\\', '/', substr($file->getPathname(), strlen($this->dataDir) + 1));
            }
        }
        sort($files);

        return $files;
    }

    private function delete(int $mediaId, bool $confirm = true): ResponseInterface
    {
        return $this->adminPost("/admin/media-library/$mediaId/delete", $confirm ? ['confirm' => 'yes'] : []);
    }

    public function testTheListShowsEveryItemWhereItIsUsed(): void
    {
        $photo = $this->media('holiday.jpg');
        $pdf = $this->media('manual.pdf', 'file', [['file', 'pdf'], ['preview', 'jpg']]);
        $song = $this->media('song.m4a', 'audio', [['aac', 'm4a']]);
        $spare = $this->media('spare.jpg');
        $article = $this->article(['title' => 'Summer <trip>', 'body' => self::doc([self::image($photo)])]);
        $page = $this->article(['title' => 'Downloads', 'kind' => 'page', 'draft' => ['title' => 'Downloads', 'body' => json_decode(self::doc([['type' => 'file', 'attrs' => ['mediaId' => $pdf, 'caption' => '']]]))]]);
        $this->setting('favicon', $song);

        $response = $this->get('/admin/media-library', $this->admin);
        $html = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode(), $html);
        foreach (['holiday.jpg', 'manual.pdf', 'song.m4a', 'spare.jpg'] as $name) {
            self::assertStringContainsString($name, $html);
        }
        self::assertStringContainsString('href="/admin/articles/' . $article . '">Summer &lt;trip&gt;</a>', $html);
        self::assertStringContainsString('href="/admin/articles/' . $page . '">Downloads</a>', $html);
        self::assertStringContainsString('href="/admin/themes#look">Site look</a>', $html);
        self::assertStringContainsString('site icon', $html);
        self::assertStringContainsString('/media/' . MediaStore::relativeVariantPath($this->sha($photo), 'w480-webp', 'webp'), $html, 'images get their small version');
        self::assertStringContainsString('/media/' . MediaStore::relativeVariantPath($this->sha($pdf), 'preview', 'jpg'), $html, 'a PDF its first page');
        self::assertStringContainsString('Not used', $html);
        self::assertSame(3, substr_count($html, ' disabled aria-describedby="why-'), 'only the unused one can be deleted');
        self::assertStringContainsString('action="/admin/media-library/' . $spare . '/delete"', $html);
        self::assertStringContainsString('aria-current="page">Media</a>', $html, 'in the admin menu');

        $unused = (string) $this->get('/admin/media-library?show=unused', $this->admin)->getBody();
        self::assertStringContainsString('spare.jpg', $unused);
        self::assertStringNotContainsString('holiday.jpg', $unused);
        self::assertStringNotContainsString('song.m4a', $unused);
    }

    public function testTheListSortsBySize(): void
    {
        $small = $this->media('small.jpg', 'image', [['w480-webp', 'webp']]);
        $large = $this->media('large.jpg', 'image', [['w480-webp', 'webp'], ['w960-webp', 'webp'], ['full', 'jpg']]);

        $newest = array_column($this->service(MediaInventory::class)->items(), 'id');
        $largest = array_column($this->service(MediaInventory::class)->items(false, 'size'), 'id');

        self::assertSame([$large, $small], $newest);
        self::assertSame([$large, $small], $largest);

        $this->media('newer-but-smallest.jpg', 'image', []);
        $largest = array_column($this->service(MediaInventory::class)->items(false, 'size'), 'name');
        self::assertSame(['large.jpg', 'small.jpg', 'newer-but-smallest.jpg'], $largest);
        $html = (string) $this->get('/admin/media-library?sort=size', $this->admin)->getBody();
        self::assertLessThan(strpos($html, 'newer-but-smallest.jpg'), strpos($html, 'large.jpg'));
    }

    public function testUsesAreFoundInTextDraftCoverTrashAndSettings(): void
    {
        $inventory = $this->service(MediaInventory::class);
        $inText = $this->media('text.jpg');
        $inGallery = $this->media('gallery.jpg');
        $inDraft = $this->media('draft.jpg');
        $cover = $this->media('cover.jpg');
        $draftCover = $this->media('draft-cover.jpg');
        $inTrash = $this->media('trash.jpg');
        $logo = $this->media('logo.png');
        $icon = $this->media('icon.png');
        $unused = $this->media('unused.jpg');

        $nested = ['type' => 'bulletList', 'content' => [['type' => 'listItem', 'content' => [
            ['type' => 'gallery', 'attrs' => ['items' => [['mediaId' => $inGallery, 'alt' => '']]]],
        ]]]];
        $live = $this->article(['title' => 'Live', 'body' => self::doc([self::image($inText), $nested]), 'cover' => $cover]);
        $drafted = $this->article(['title' => 'Old title', 'draft' => ['title' => 'Draft title', 'body' => json_decode(self::doc([self::image($inDraft)])), 'cover' => $draftCover]]);
        $this->article(['title' => 'Binned', 'kind' => 'page', 'body' => self::doc([self::image($inTrash)]), 'trashed' => true]);
        $this->setting('logo', $logo);
        $this->setting('favicon', $icon);

        self::assertSame([['type' => 'article', 'title' => 'Live', 'url' => "/admin/articles/$live", 'places' => ['text'], 'trashed' => false]], $inventory->usesOf($inText));
        self::assertSame(['text'], $inventory->usesOf($inGallery)[0]['places'] ?? null, 'nested in a list, inside a gallery');
        self::assertSame(['cover'], $inventory->usesOf($cover)[0]['places'] ?? null);
        self::assertSame([['type' => 'article', 'title' => 'Draft title', 'url' => "/admin/articles/$drafted", 'places' => ['draft'], 'trashed' => false]], $inventory->usesOf($inDraft));
        self::assertSame(['draft cover'], $inventory->usesOf($draftCover)[0]['places'] ?? null);
        self::assertSame([['type' => 'page', 'title' => 'Binned', 'url' => '/admin/articles?trash=1&kind=page', 'places' => ['text'], 'trashed' => true]], $inventory->usesOf($inTrash));
        self::assertSame([['type' => 'site', 'title' => 'Site look', 'url' => '/admin/themes#look', 'places' => ['logo'], 'trashed' => false]], $inventory->usesOf($logo));
        self::assertSame(['site icon'], $inventory->usesOf($icon)[0]['places'] ?? null);
        self::assertSame([], $inventory->usesOf($unused));

        $unusedIds = array_column($inventory->items(true), 'id');
        self::assertSame([$unused], $unusedIds);
    }

    public function testAnItemInUseIsNotDeleted(): void
    {
        $photo = $this->media('holiday.jpg');
        $this->article(['title' => 'Summer', 'body' => self::doc([self::image($photo)])]);
        $files = $this->files();

        $response = $this->delete($photo);

        self::assertSame(409, $response->getStatusCode());
        self::assertStringContainsString('still used in “Summer”', (string) $response->getBody());
        self::assertSame(1, $this->rows('media'));
        self::assertSame(3, $this->rows('media_variant'));
        self::assertSame($files, $this->files());
    }

    public function testTheSiteLookAndTheTrashKeepAnItemToo(): void
    {
        $logo = $this->media('logo.png');
        $binned = $this->media('binned.jpg');
        $this->setting('logo', $logo);
        $this->article(['body' => self::doc([self::image($binned)]), 'trashed' => true]);

        self::assertSame(409, $this->delete($logo)->getStatusCode());
        self::assertStringContainsString('the site look', (string) $this->delete($logo)->getBody());
        self::assertSame(409, $this->delete($binned)->getStatusCode());
        self::assertSame(2, $this->rows('media'));
    }

    public function testDeletingRemovesTheRowsFilesAndWaitingJobs(): void
    {
        $keep = $this->media('keep.jpg');
        $gone = $this->media('gone.jpg', 'image', [['w480-webp', 'webp'], ['w480-jpg', 'jpg'], ['full', 'jpg'], ['icon-192', 'png']]);
        $sha = $this->sha($gone);
        $this->service(JobQueue::class)->enqueue('image', ['media_id' => $gone]);
        $this->service(JobQueue::class)->enqueue('image', ['media_id' => $keep]);
        $this->pdo->prepare("INSERT INTO upload (id, original_name, total_bytes, received_bytes, created_at, media_id) VALUES ('u1', 'gone.jpg', 1000, 1000, now(), ?)")->execute([$gone]);
        $before = $this->service(MediaLibrary::class)->usage();

        $response = $this->delete($gone);

        self::assertSame(303, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('/admin/media-library?done=deleted', $response->getHeaderLine('Location'));
        self::assertSame(0, $this->rows('media', "id = $gone"));
        self::assertSame(0, $this->rows('media_variant', "media_id = $gone"));
        self::assertSame(1, $this->rows('job'), 'only the other item\'s job is left');
        self::assertSame(0, $this->rows('upload'), 'a finished upload must not turn back into an unfinished one');
        foreach ($this->files() as $file) {
            self::assertStringNotContainsString($sha, $file);
        }
        self::assertCount(4, $this->files(), 'the other item keeps its original and versions');
        $prefix = substr($sha, 0, 2) . '/' . substr($sha, 2, 2);
        if ($prefix !== substr($this->sha($keep), 0, 2) . '/' . substr($this->sha($keep), 2, 2)) {
            self::assertDirectoryDoesNotExist($this->dataDir . '/private/' . $prefix, 'empty fan-out directories go too');
            self::assertDirectoryDoesNotExist($this->dataDir . '/media/' . $prefix);
        }

        $after = $this->service(MediaLibrary::class)->usage();
        self::assertSame($before['usedBytes'] - 1400, $after['usedBytes'], 'the dashboard counts what is left');
        self::assertSame($before['files'] - 5, $after['files']);
        self::assertStringContainsString('Deleted, with all its versions.', (string) $this->get('/admin/media-library?done=deleted', $this->admin)->getBody());
    }

    public function testDeletingKeepsTheViewItCameFrom(): void
    {
        $id = $this->media('spare.jpg');

        $response = $this->adminPost("/admin/media-library/$id/delete", ['confirm' => 'yes', 'show' => 'unused', 'sort' => 'size', 'page' => '1']);

        self::assertSame('/admin/media-library?show=unused&sort=size&done=deleted', $response->getHeaderLine('Location'));
    }

    public function testDeletingNeedsTheBoxTicked(): void
    {
        $id = $this->media('spare.jpg');

        $response = $this->delete($id, false);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(1, $this->rows('media'));
    }

    public function testAnItemBeingProcessedIsNotDeleted(): void
    {
        $id = $this->media('busy.jpg', 'image', [], 'processing');
        $this->pdo->prepare("INSERT INTO job (type, payload, state, run_after, created_at) VALUES ('image', ?::jsonb, 'running', now(), now())")
            ->execute([json_encode(['media_id' => $id])]);

        $response = $this->delete($id);

        self::assertSame(409, $response->getStatusCode());
        self::assertStringContainsString('being processed', (string) $response->getBody());
        self::assertSame(1, $this->rows('media'));
    }

    public function testDeletingTwiceSaysItIsGone(): void
    {
        $id = $this->media('spare.jpg');
        $this->delete($id);

        $response = $this->delete($id);

        self::assertSame(409, $response->getStatusCode());
        self::assertStringContainsString('already deleted', (string) $response->getBody());
    }

    public function testTheOriginalStaysWhileAnotherRowHasTheSameContent(): void
    {
        $id = $this->media('spare.jpg');
        $sha = $this->sha($id);
        $store = $this->service(MediaStore::class);
        $inventory = $this->service(MediaInventory::class);

        // What a second row with the same content would look like, if the unique index ever allowed one.
        $this->pdo->exec('ALTER TABLE media DROP CONSTRAINT media_sha256_key');
        try {
            $this->pdo->prepare("INSERT INTO media (sha256, kind, extension, bytes, original_name, status, created_at) VALUES (?, 'image', 'jpg', 1000, 'twin.jpg', 'ready', now())")->execute([$sha]);
            $inventory->delete($id);

            self::assertFileExists($store->originalPath($sha));
            self::assertSame(1, $this->rows('media'));
        } finally {
            $this->pdo->exec('DELETE FROM media');
            $this->pdo->exec('ALTER TABLE media ADD CONSTRAINT media_sha256_key UNIQUE (sha256)');
        }
    }

    public function testRoutesNeedAdminAndCsrf(): void
    {
        $id = $this->media('spare.jpg');

        self::assertSame(303, $this->get('/admin/media-library')->getStatusCode());
        self::assertSame(303, $this->post("/admin/media-library/$id/delete", ['confirm' => 'yes'])->getStatusCode());
        self::assertSame(403, $this->post("/admin/media-library/$id/delete", ['confirm' => 'yes'], $this->admin)->getStatusCode());
        self::assertSame(1, $this->rows('media'));
    }

    public function testTheDashboardPointsHereWhenStorageRunsLow(): void
    {
        $this->settings = ['MEDIA_MAX_BYTES' => '1400'];
        $this->pdo->exec('TRUNCATE app_user CASCADE');
        parent::setUp();
        $this->signIn();
        $this->media('big.jpg'); // 1300 bytes with its versions, over 90 % of the cap

        $html = (string) $this->get('/admin', $this->admin)->getBody();

        self::assertStringContainsString('<a href="/admin/media-library?show=unused&amp;sort=size">remove media nobody needs</a>', $html);
    }

    /**
     * Published versions kept for restoring may still show a deleted item. Restoring
     * one brings its ID back into the draft, and nothing may break over it.
     */
    public function testAnOldVersionMayLoseAnItemAndStillWorks(): void
    {
        $photo = $this->media('holiday.jpg');
        $create = $this->adminPost('/admin/articles', ['kind' => 'article']);
        $id = (int) substr($create->getHeaderLine('Location'), strlen('/admin/articles/'));
        $draft = ['title' => 'Summer', 'slug' => 'summer', 'lang' => 'en', 'teaser' => '', 'tags' => '', 'cover' => $photo, 'body' => self::tree(self::doc([
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Sunny.']]],
            self::image($photo),
        ]))];
        $publish = function (array $draft) use ($id): void {
            $version = $this->service(Articles::class)->find($id)?->draftVersion;
            self::assertSame(200, $this->adminPost("/admin/articles/$id/autosave", ['version' => $version, 'writer' => 'tab', 'draft' => $draft])->getStatusCode());
            $version = $this->service(Articles::class)->find($id)?->draftVersion;
            $response = $this->adminPost("/admin/articles/$id/publish", ['version' => $version]);
            self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        };
        $publish($draft);
        $publish(['cover' => null, 'body' => self::tree(self::doc([['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Rainy.']]]]))] + $draft);

        $inventory = $this->service(MediaInventory::class);
        self::assertSame([], $inventory->usesOf($photo), 'old versions don\'t keep an item');
        self::assertSame(1, $inventory->oldVersionsOf($photo));
        self::assertStringContainsString('Also in 1 old version, which would show a gap where it was.', (string) $this->get('/admin/media-library', $this->admin)->getBody());

        self::assertSame(303, $this->delete($photo)->getStatusCode());

        $revision = $this->pdo->query("SELECT min(id) FROM article_revision WHERE article_id = $id");
        self::assertNotFalse($revision);
        $restore = $this->adminPost("/admin/articles/$id/revisions/" . (int) $revision->fetchColumn() . '/restore');
        self::assertSame(303, $restore->getStatusCode());

        // The editor gets the page, and a 404 for the item, which it shows as a placeholder.
        $editor = $this->get("/admin/articles/$id", $this->admin);
        self::assertSame(200, $editor->getStatusCode());
        self::assertStringContainsString('id="cover-missing"', (string) $editor->getBody());
        self::assertSame(404, $this->get("/admin/media/$photo", $this->admin)->getStatusCode());
        self::assertSame(200, $this->get("/admin/articles/$id/preview", $this->admin)->getStatusCode());

        // Readers see the text, and nothing where the picture was.
        $publish(['cover' => $photo, 'body' => self::tree(self::doc([
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Sunny.']]],
            self::image($photo),
        ]))] + $draft);
        $page = $this->get('/summer');
        $html = (string) $page->getBody();
        self::assertSame(200, $page->getStatusCode(), $html);
        self::assertStringContainsString('Sunny.', $html);
        self::assertStringNotContainsString('<figure', $html);
        self::assertStringNotContainsString('article-cover', $html);
        self::assertSame(200, $this->get('/')->getStatusCode());
        self::assertSame(200, $this->get('/feed.atom')->getStatusCode());
    }
}
