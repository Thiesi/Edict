<?php

declare(strict_types=1);

namespace Edict\Tests\Backup;

use Edict\Auth\AdminAccount;
use Edict\Auth\SessionStore;
use Edict\Auth\Totp;
use Edict\Backup\Restore;
use Edict\Backup\RestoreRefused;
use Edict\Backup\RestoreResult;
use Edict\Cli\ContentRestoreCommand;
use Edict\Content\Articles;
use Edict\Database\Row;
use Edict\Media\AvProcessor;
use Edict\Media\IconProcessor;
use Edict\Media\ImageProcessor;
use Edict\Media\MediaStore;
use Edict\Services;
use Edict\Site\Settings;
use Edict\Tests\Content\Doc;
use Edict\Tests\DbTestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\UploadedFile;
use Symfony\Component\Console\Tester\CommandTester;

final class RestoreFlowTest extends DbTestCase
{
    private const SESSION = 'edict_session';
    private const PASSWORD = 'correct horse battery';

    private string $dataDir;
    private string $secret;

    /** @var array<string, string> */
    private array $admin;
    private string $csrf;

    protected function setUp(): void
    {
        $this->dataDir = sys_get_temp_dir() . '/edict-restore-test-' . bin2hex(random_bytes(6));
        mkdir($this->dataDir);
        parent::setUp();
        $this->secret = $this->service(AdminAccount::class)->create('sovereign', self::PASSWORD);

        $id = $this->pdo->query('SELECT id FROM app_user WHERE is_admin');
        self::assertNotFalse($id);
        $sessions = $this->service(SessionStore::class);
        $token = $sessions->create((int) $id->fetchColumn(), 365 * 86400);
        $session = $sessions->load($token);
        self::assertNotNull($session);
        $this->admin = [self::SESSION => $token];
        $this->csrf = $session->csrfToken;
    }

    protected function tearDown(): void
    {
        MediaStore::remove($this->dataDir);
        parent::tearDown();
    }

    protected function extraEnv(): array
    {
        return ['DATA_DIR' => $this->dataDir, 'MEDIA_MIN_FREE_BYTES' => '0'];
    }

    // --- Helpers ---------------------------------------------------------------

    /**
     * @param array<string, mixed> $fields
     */
    private function adminPost(string $path, array $fields = []): ResponseInterface
    {
        /** @var array<string, string> $fields */
        return $this->post($path, $fields + ['_csrf' => $this->csrf], $this->admin);
    }

    private function newRow(string $kind = 'article'): int
    {
        $response = $this->adminPost('/admin/articles', ['kind' => $kind]);
        self::assertSame(303, $response->getStatusCode(), (string) $response->getBody());

        return (int) substr($response->getHeaderLine('Location'), strlen('/admin/articles/'));
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function save(int $id, array $fields): void
    {
        $version = $this->service(Articles::class)->find($id)?->draftVersion;
        $draft = $fields + ['title' => 'Untitled', 'slug' => '', 'lang' => 'en', 'teaser' => '', 'body' => json_decode(Doc::text('Text'), true), 'tags' => ''];
        $response = $this->adminPost("/admin/articles/$id/autosave", ['version' => $version, 'writer' => 'test-tab', 'draft' => $draft]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
    }

    private function publish(int $id): void
    {
        $version = $this->service(Articles::class)->find($id)?->draftVersion;
        $response = $this->adminPost("/admin/articles/$id/publish", ['version' => $version]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function published(array $fields, string $kind = 'article'): int
    {
        $id = $this->newRow($kind);
        $this->save($id, $fields);
        $this->publish($id);

        return $id;
    }

    /**
     * A media row with its original on disk, as an upload leaves it.
     */
    private function media(string $kind, string $extension, string $name, string $bytes): int
    {
        $sha256 = hash('sha256', $bytes);
        $path = $this->service(MediaStore::class)->originalPath($sha256);
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0o700, true);
        }
        file_put_contents($path, $bytes);
        $stmt = $this->pdo->prepare(
            "INSERT INTO media (sha256, kind, extension, bytes, original_name, width, height, status, created_at)
             VALUES (?, ?, ?, ?, ?, 640, 480, 'ready', to_timestamp(?)) RETURNING id",
        );
        $stmt->execute([$sha256, $kind, $extension, strlen($bytes), $name, $this->clock->now()]);

        return (int) $stmt->fetchColumn();
    }

    private function originalOf(int $mediaId): string
    {
        $stmt = $this->pdo->prepare('SELECT sha256 FROM media WHERE id = ?');
        $stmt->execute([$mediaId]);

        return $this->service(MediaStore::class)->originalPath((string) $stmt->fetchColumn());
    }

    /**
     * @return array<string, mixed>
     */
    private function export(): array
    {
        $response = $this->get('/admin/export', $this->admin);
        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertIsArray($data);

        /** @var array<string, mixed> $data */
        return $data;
    }

    /**
     * Everything the export holds, gone, as after losing the database. The admin
     * account stays: in real life it is created again before restoring.
     */
    private function loseContent(): void
    {
        $this->pdo->exec('TRUNCATE article, tag, media, upload, job, setting RESTART IDENTITY CASCADE');
    }

    private function rows(string $table): int
    {
        $stmt = $this->pdo->query("SELECT count(*) FROM $table");
        self::assertNotFalse($stmt);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @param array<mixed> $data
     */
    private function restore(array $data): RestoreResult
    {
        return $this->service(Restore::class)->fromJson(json_encode($data, JSON_THROW_ON_ERROR));
    }

    /**
     * @param array<mixed> $data
     */
    private function refused(array $data, string $message): void
    {
        try {
            $this->restore($data);
            self::fail("Restored although: $message");
        } catch (RestoreRefused $e) {
            self::assertStringContainsString($message, $e->getMessage());
        }
    }

    /**
     * @return list<array{type: string, payload: array<mixed>}>
     */
    private function jobs(): array
    {
        $stmt = $this->pdo->query('SELECT type, payload::text AS payload FROM job ORDER BY id');
        self::assertNotFalse($stmt);
        $jobs = [];
        foreach ($stmt->fetchAll() as $row) {
            self::assertIsArray($row);
            $payload = json_decode(Row::str($row, 'payload'), true);
            self::assertIsArray($payload);
            $jobs[] = ['type' => Row::str($row, 'type'), 'payload' => $payload];
        }

        return $jobs;
    }

    private function code(): string
    {
        $code = Totp::code($this->secret, intdiv($this->clock->now(), 30));
        $this->clock->advance(31);

        return $code;
    }

    /**
     * @param array<string, string> $fields
     */
    private function restoreForm(string $json, array $fields): ResponseInterface
    {
        $path = $this->dataDir . '/upload-' . bin2hex(random_bytes(4)) . '.json';
        file_put_contents($path, $json);

        return $this->postFiles('/admin/restore', $fields + ['_csrf' => $this->csrf], ['file' => new UploadedFile($path, 'edict-export.json', 'application/json', strlen($json))], $this->admin);
    }

    /**
     * A site with one of everything the export carries.
     *
     * @return array{image: int, video: int, story: int, page: int, draft: int, pending: int, trashed: int}
     */
    private function site(): array
    {
        $image = $this->media('image', 'jpg', 'lighthouse.jpg', 'jpeg bytes of a lighthouse');
        $video = $this->media('video', 'mp4', 'waves.mp4', 'mp4 bytes of waves');
        // The author chose the frame at 1.5 seconds as the poster.
        $this->pdo->prepare("INSERT INTO media_variant (media_id, preset, format, width, height, bytes) VALUES (?, 'poster-1500', 'jpg', 640, 480, 10)")->execute([$video]);

        $body = ['type' => 'doc', 'content' => [
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'The lighthouse keepers left in spring.']]],
            ['type' => 'image', 'attrs' => ['mediaId' => $image, 'alt' => 'A lighthouse at dusk', 'caption' => 'Dusk']],
            ['type' => 'player', 'attrs' => ['mediaId' => $video, 'caption' => 'Waves']],
        ]];
        $story = $this->published(['title' => 'Keepers', 'slug' => 'first-name', 'body' => $body, 'tags' => 'Sea, Light', 'cover' => (string) $image, 'coverAlt' => 'The tower']);
        $this->clock->advance(3600);
        $this->save($story, ['title' => 'Keepers', 'slug' => 'keepers', 'body' => $body, 'tags' => 'Sea, Light', 'cover' => (string) $image, 'coverAlt' => 'The tower', 'teaser' => 'Gone']);
        $this->publish($story);
        $this->pdo->prepare("UPDATE article SET comments_mode = 'closed' WHERE id = ?")->execute([$story]);

        $page = $this->published(['title' => 'Impressum', 'slug' => 'impressum', 'lang' => 'de', 'body' => json_decode(Doc::text('Angaben gemäß § 5 DDG'), true)], 'page');

        $draft = $this->newRow();
        $this->save($draft, ['title' => 'Only a draft', 'tags' => 'Sea']);

        $pending = $this->published(['title' => 'Live text', 'slug' => 'live', 'tags' => 'Light']);
        $this->save($pending, ['title' => 'Better text', 'slug' => 'live', 'tags' => 'Light, Ideas']);

        $trashed = $this->published(['title' => 'Regretted', 'slug' => 'regretted']);
        $this->adminPost("/admin/articles/$trashed/unpublish");
        $this->adminPost("/admin/articles/$trashed/trash");

        $settings = $this->service(Settings::class);
        self::assertSame([], $settings->save(['title' => 'Logbook', 'description' => 'Notes from the coast', 'menu' => "Archive | /archive\nAbout | /about", 'footer' => 'Imprint | /impressum', 'imprint' => 'impressum', 'privacy' => '']));
        self::assertSame([], $settings->saveLook(['accent_mode' => 'own', 'accent' => '#1a56db', 'logo_only' => '1'], $image, $image));
        self::assertSame([], $settings->saveCommentRules(['comment_default' => 'hold', 'comment_auto_close_days' => '30', 'comment_words' => "spam\neggs", 'comment_word_action' => 'reject', 'comment_alerts' => '1']));
        self::assertTrue($settings->applyTheme('broadsheet'));

        return ['image' => $image, 'video' => $video, 'story' => $story, 'page' => $page, 'draft' => $draft, 'pending' => $pending, 'trashed' => $trashed];
    }

    /**
     * @param array<string, mixed> $export
     *
     * @return array<string, mixed> what a restore must bring back unchanged
     */
    private static function content(array $export): array
    {
        $media = $export['media'];
        self::assertIsArray($media);
        foreach ($media as $i => $row) {
            self::assertIsArray($row);
            unset($row['status']); // Restored media are processed again.
            $media[$i] = $row;
        }

        return ['settings' => $export['settings'], 'media' => $media, 'articles' => $export['articles'], 'tags' => $export['tags']];
    }

    // --- Tests -----------------------------------------------------------------

    public function testTheExportHoldsWhatARestoreNeeds(): void
    {
        $ids = $this->site();
        $export = $this->export();

        self::assertSame('edict-export', $export['format']);
        self::assertSame(2, $export['version']);
        self::assertIsArray($export['settings']);
        self::assertSame('Logbook', $export['settings']['title']);
        self::assertSame($ids['image'], $export['settings']['favicon']);
        self::assertSame(['spam', 'eggs'], $export['settings']['comment_words']);
        self::assertSame('broadsheet', $export['settings']['theme']);

        self::assertIsArray($export['media']);
        $video = array_column($export['media'], null, 'id')[$ids['video']];
        self::assertIsArray($video);
        self::assertSame(['id', 'sha256', 'kind', 'extension', 'bytes', 'original_name', 'width', 'height', 'status', 'created_at', 'poster_seconds'], array_keys($video));
        self::assertSame(1.5, $video['poster_seconds']);
        self::assertSame(hash('sha256', 'mp4 bytes of waves'), $video['sha256']);

        self::assertIsArray($export['articles']);
        $story = array_column($export['articles'], null, 'id')[$ids['story']];
        self::assertIsArray($story);
        self::assertSame(['first-name'], $story['old_slugs']);
        self::assertSame('closed', $story['comments_mode']);
        self::assertIsString($story['created_at']);
        $trashed = array_column($export['articles'], null, 'id')[$ids['trashed']];
        self::assertIsArray($trashed);
        self::assertTrue($trashed['in_trash']);
        self::assertIsString($trashed['deleted_at']);

        $json = (string) $this->get('/admin/export', $this->admin)->getBody();
        foreach (['sovereign', 'password', 'totp', 'session', 'comment_id', 'votes'] as $private) {
            self::assertStringNotContainsStringIgnoringCase('"' . $private, $json, 'no accounts, secrets, comments or likes');
        }
    }

    public function testAnExportRestoresIntoAnEmptyInstallUnchanged(): void
    {
        $ids = $this->site();
        $browser = ['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:132.0) Gecko/20100101 Firefox/132.0'];
        $this->get('/keepers', [], $browser);
        self::assertSame(1, $this->rows('article_view_day'));
        $before = $this->export();
        self::assertStringNotContainsString('views', json_encode($before, JSON_THROW_ON_ERROR), 'view counts stay out of the export');
        $this->loseContent();

        $result = $this->restore($before);

        self::assertSame(2, $result->version);
        self::assertSame(4, $result->articles);
        self::assertSame(1, $result->pages);
        self::assertSame(2, $result->tags, 'Sea and Light; Ideas is only in a draft, so not a tag yet');
        self::assertSame(2, $result->mediaRelinked);
        self::assertSame([], $result->mediaMissing);
        self::assertTrue($result->settingsRestored);

        self::assertEquals(self::content($before), self::content($this->export()));

        // The worker makes the versions again: thumbnails, the video and its chosen poster, the site icons.
        self::assertEquals([
            ['type' => ImageProcessor::JOB, 'payload' => ['media_id' => $ids['image']]],
            ['type' => AvProcessor::JOB, 'payload' => ['media_id' => $ids['video']]],
            ['type' => IconProcessor::JOB, 'payload' => ['media_id' => $ids['image']]],
        ], $this->jobs());
        $meta = $this->pdo->query("SELECT status, meta->>'restore_poster' AS poster FROM media WHERE id = " . $ids['video']);
        self::assertNotFalse($meta);
        self::assertSame(['status' => 'processing', 'poster' => '1.5'], $meta->fetch(\PDO::FETCH_ASSOC));

        // Readers find it again: the article, its old address, the search, the tag.
        self::assertSame(200, $this->get('/keepers')->getStatusCode());
        $old = $this->get('/first-name');
        self::assertSame(301, $old->getStatusCode());
        self::assertSame('/keepers', $old->getHeaderLine('Location'));
        self::assertStringContainsString('href="/keepers"', (string) $this->get('/search?q=lighthouse')->getBody(), 'the search index is rebuilt');
        self::assertStringContainsString('href="/keepers"', (string) $this->get('/tag/sea')->getBody());
        self::assertSame(404, $this->get('/regretted')->getStatusCode(), 'still in the trash');
        self::assertSame(0, $this->rows('article_view_day'), 'the counts start again');
        $this->get('/keepers', [], ['User-Agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_1 like Mac OS X) Version/18.1 Mobile/15E148 Safari/604.1']);
        self::assertSame(1, $this->rows('article_view_day'));

        // New rows continue after the restored ids.
        self::assertGreaterThan(max($ids), $this->newRow());
        $next = $this->pdo->query("INSERT INTO media (sha256, kind, extension, bytes, original_name, status, created_at) VALUES ('x', 'file', 'txt', 1, 'x', 'ready', now()) RETURNING id");
        self::assertNotFalse($next);
        self::assertGreaterThan($ids['video'], (int) $next->fetchColumn());
    }

    public function testAMissingMediaFileIsSkippedAndReported(): void
    {
        $ids = $this->site();
        $export = $this->export();
        $video = $this->originalOf($ids['video']);
        $this->loseContent();
        unlink($video);

        $result = $this->restore($export);

        self::assertSame(1, $result->mediaRelinked);
        self::assertSame(['waves.mp4 (' . substr(hash('sha256', 'mp4 bytes of waves'), 0, 12) . ')'], $result->mediaMissing);
        self::assertStringContainsString('1 missing', implode("\n", $result->lines()));
        self::assertSame(1, $this->rows('media'));
        self::assertSame(5, $this->rows('article'), 'the articles come back all the same');
        $jobs = array_column($this->jobs(), 'type');
        self::assertNotContains(AvProcessor::JOB, $jobs);

        // The missing id stays free, so no new upload ever shows up in its place.
        $next = $this->pdo->query("INSERT INTO media (sha256, kind, extension, bytes, original_name, status, created_at) VALUES ('x', 'file', 'txt', 1, 'x', 'ready', now()) RETURNING id");
        self::assertNotFalse($next);
        self::assertGreaterThan($ids['video'], (int) $next->fetchColumn());
    }

    public function testAMissingCoverOrLogoIsDropped(): void
    {
        $ids = $this->site();
        $export = $this->export();
        $image = $this->originalOf($ids['image']);
        $this->loseContent();
        unlink($image);

        $this->restore($export);

        self::assertNull($this->service(Articles::class)->find($ids['story'])?->coverMediaId);
        self::assertSame(0, $this->service(Settings::class)->site()->logo);
        self::assertSame(0, $this->service(Settings::class)->site()->favicon);
        self::assertNotContains(IconProcessor::JOB, array_column($this->jobs(), 'type'));
    }

    public function testAnInstallWithContentIsRefused(): void
    {
        $this->site();
        $export = $this->export();

        $this->refused($export, 'already has articles, pages or media');
        self::assertSame(5, $this->rows('article'));

        // Media alone count too: their ids would clash.
        $this->loseContent();
        $this->media('image', 'png', 'logo.png', 'png bytes');
        $this->refused($export, 'already has articles, pages or media');
        self::assertSame(0, $this->rows('article'));
    }

    public function testInvalidDataIsRefusedWithNothingWritten(): void
    {
        $ids = $this->site();
        $export = $this->export();
        $this->loseContent();

        $cases = [
            'not a body' => [['articles', 1, 'body'], ['type' => 'paragraph'], 'has no valid body'],
            'too deep' => [['articles', 1, 'body'], self::deepBody(150), 'nested too deeply'],
            'oversized gallery' => [['articles', 1, 'body'], ['type' => 'doc', 'content' => [['type' => 'gallery', 'attrs' => ['items' => array_fill(0, 101, ['mediaId' => 1, 'alt' => ''])]]]], 'up to 100 images'],
            'bad draft' => [['articles', 1, 'draft'], ['title' => 'x', 'body' => self::deepBody(150)], 'draft: The lists or quotes'],
            'duplicate slug' => [['articles', 1, 'slug'], 'keepers', 'The address "keepers" is used twice'],
            'old slug taken' => [['articles', 1, 'slug'], 'first-name', 'The address "first-name" is used twice'],
            'bad slug' => [['articles', 1, 'slug'], 'Not A Slug', 'no valid address'],
            'duplicate id' => [['articles', 1, 'id'], $ids['story'], 'appears twice'],
            'bad status' => [['articles', 1, 'status'], 'live', 'unknown status'],
            'bad date' => [['articles', 1, 'updated_at'], 'yesterday', 'not a valid date'],
            'bad media hash' => [['media', 0, 'sha256'], '../../etc/passwd', 'no valid SHA-256'],
            'bad setting' => [['settings', 'menu'], 'Archive | /archive', 'The setting "menu" has the wrong type'],
            'unknown version' => [['version'], 3, 'version 3'],
            'unknown format' => [['format'], 'wordpress-wxr', 'format is unknown'],
        ];
        foreach ($cases as $name => [$path, $value, $message]) {
            $this->refused(self::with($export, $path, $value), $message);
            foreach (['article', 'tag', 'media', 'job', 'setting', 'slug_redirect'] as $table) {
                self::assertSame(0, $this->rows($table), "$name: nothing in $table");
            }
        }

        $this->expectException(RestoreRefused::class);
        $this->expectExceptionMessage('not a JSON object');
        $this->service(Restore::class)->fromJson('[1, 2, 3]');
    }

    public function testTheDashboardRestoreNeedsPasswordAndCode(): void
    {
        $this->site();
        $json = (string) $this->get('/admin/export', $this->admin)->getBody();

        self::assertStringNotContainsString('action="/admin/restore"', (string) $this->get('/admin', $this->admin)->getBody(), 'not offered while there is content');
        $refused = $this->restoreForm($json, ['password' => self::PASSWORD, 'code' => $this->code()]);
        self::assertSame(422, $refused->getStatusCode());
        self::assertStringContainsString('already has articles, pages or media', (string) $refused->getBody());

        $this->loseContent();
        $dashboard = (string) $this->get('/admin', $this->admin)->getBody();
        self::assertStringContainsString('action="/admin/restore"', $dashboard);
        self::assertStringContainsString('enctype="multipart/form-data"', $dashboard);

        $noCode = $this->restoreForm($json, ['password' => self::PASSWORD, 'code' => '000000']);
        self::assertSame(403, $noCode->getStatusCode());
        self::assertStringContainsString('nothing was restored', (string) $noCode->getBody());
        $noPassword = $this->restoreForm($json, ['password' => 'wrong wrong wrong', 'code' => $this->code()]);
        self::assertSame(403, $noPassword->getStatusCode());
        self::assertSame(0, $this->rows('article'));

        $noCsrf = $this->postFiles('/admin/restore', ['password' => self::PASSWORD, 'code' => $this->code()], [], $this->admin);
        self::assertSame(403, $noCsrf->getStatusCode());
        self::assertSame(303, $this->restoreFormAnonymous($json), 'admins only');

        $done = $this->restoreForm($json, ['password' => self::PASSWORD, 'code' => $this->code()]);
        self::assertSame(200, $done->getStatusCode(), (string) $done->getBody());
        $html = (string) $done->getBody();
        self::assertStringContainsString('4 articles, 1 page, 2 tags restored.', $html);
        self::assertStringContainsString('2 media files relinked, 0 missing.', $html);
        self::assertStringContainsString('Settings restored.', $html);
        self::assertSame(5, $this->rows('article'));
    }

    private function restoreFormAnonymous(string $json): int
    {
        $path = $this->dataDir . '/anonymous.json';
        file_put_contents($path, $json);

        return $this->postFiles('/admin/restore', ['password' => self::PASSWORD, 'code' => '000000'], ['file' => new UploadedFile($path, 'x.json', 'application/json', strlen($json))], [])->getStatusCode();
    }

    public function testTheCommandRestoresAFile(): void
    {
        $this->site();
        $path = $this->dataDir . '/edict-export.json';
        file_put_contents($path, (string) $this->get('/admin/export', $this->admin)->getBody());
        $this->loseContent();

        $tester = new CommandTester(new ContentRestoreCommand($this->service(Services::class)));
        self::assertSame(0, $tester->execute(['file' => $path]));
        self::assertStringContainsString('4 articles, 1 page, 2 tags restored.', $tester->getDisplay());
        self::assertSame(5, $this->rows('article'));

        $again = new CommandTester(new ContentRestoreCommand($this->service(Services::class)));
        self::assertSame(1, $again->execute(['file' => $path]));
        self::assertStringContainsString('Nothing was restored. This install already has', $again->getDisplay());

        $missing = new CommandTester(new ContentRestoreCommand($this->service(Services::class)));
        self::assertSame(1, $missing->execute(['file' => $this->dataDir . '/nope.json']));
        self::assertStringContainsString('There is no file at', $missing->getDisplay());
    }

    public function testAVersionOneExportStillRestores(): void
    {
        // What the export looked like before it had a format marker, media or settings.
        $v1 = [
            'exported_at' => '2026-11-01T10:00:00+00:00',
            'articles' => [
                [
                    'id' => 3, 'kind' => 'article', 'status' => 'published', 'slug' => 'harbour', 'lang' => 'en',
                    'title' => 'Harbour', 'teaser' => 'Boats', 'tags' => ['Sea', 'Boats'],
                    'body' => ['type' => 'doc', 'content' => [
                        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Fishing boats at anchor.']]],
                        ['type' => 'image', 'attrs' => ['mediaId' => 7, 'alt' => 'Boats', 'caption' => '']],
                    ]],
                    'cover_media_id' => 7, 'cover_alt' => 'Boats',
                    'published_at' => '2026-10-01T08:00:00+00:00', 'revised_at' => null, 'updated_at' => '2026-10-02T08:00:00+00:00',
                    'in_trash' => false, 'draft' => null,
                ],
                [
                    'id' => 5, 'kind' => 'page', 'status' => 'draft', 'slug' => 'untitled', 'lang' => 'de',
                    'title' => 'Untitled', 'teaser' => '', 'tags' => [],
                    'body' => ['type' => 'doc', 'content' => []],
                    'cover_media_id' => null, 'cover_alt' => '',
                    'published_at' => null, 'revised_at' => null, 'updated_at' => '2026-10-03T08:00:00+00:00',
                    'in_trash' => true,
                    'draft' => ['title' => 'Kontakt', 'slug' => '', 'lang' => 'de', 'teaser' => '', 'body' => ['type' => 'doc', 'content' => []], 'tags' => '', 'cover' => null, 'coverAlt' => ''],
                ],
            ],
            'tags' => [['slug' => 'boats', 'name' => 'Boats'], ['slug' => 'sea', 'name' => 'Sea']],
        ];

        $result = $this->restore($v1);

        self::assertSame(1, $result->version);
        self::assertSame([1, 1, 2, 0], [$result->articles, $result->pages, $result->tags, $result->mediaRelinked]);
        self::assertFalse($result->settingsRestored);
        self::assertStringContainsString('version 1 export', implode("\n", $result->lines()));
        self::assertStringContainsString("1 media reference in the articles (images, players, files, covers) can't be relinked", implode("\n", $result->lines()));

        $harbour = $this->service(Articles::class)->find(3);
        self::assertNotNull($harbour);
        self::assertNull($harbour->coverMediaId, 'there is no media row to point at');
        self::assertSame(['Boats', 'Sea'], array_column($harbour->tags, 'name'));
        self::assertSame(200, $this->get('/harbour')->getStatusCode());
        $page = $this->service(Articles::class)->find(5);
        self::assertNotNull($page);
        self::assertNotNull($page->deletedAt);
        self::assertSame('Kontakt', $page->input()->title);

        // A new upload can't take the id the article still points at.
        $next = $this->pdo->query("INSERT INTO media (sha256, kind, extension, bytes, original_name, status, created_at) VALUES ('x', 'file', 'txt', 1, 'x', 'ready', now()) RETURNING id");
        self::assertNotFalse($next);
        self::assertSame(8, (int) $next->fetchColumn());
        self::assertSame(6, $this->newRow());
    }

    /**
     * The data with one value replaced, deep inside.
     *
     * @param array<mixed> $data
     * @param list<int|string> $path
     *
     * @return array<mixed>
     */
    private static function with(array $data, array $path, mixed $value): array
    {
        $key = array_shift($path);
        if ($key === null) {
            return $data;
        }
        if ($path === []) {
            $data[$key] = $value;
        } else {
            $inner = $data[$key] ?? null;
            self::assertIsArray($inner);
            $data[$key] = self::with($inner, $path, $value);
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private static function deepBody(int $depth): array
    {
        $node = ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'deep']]];
        for ($i = 0; $i < $depth; ++$i) {
            $node = ['type' => 'blockquote', 'content' => [$node]];
        }

        return ['type' => 'doc', 'content' => [$node]];
    }
}
