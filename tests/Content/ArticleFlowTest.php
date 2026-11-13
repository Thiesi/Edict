<?php

declare(strict_types=1);

namespace Edict\Tests\Content;

use Edict\Auth\AdminAccount;
use Edict\Auth\SessionStore;
use Edict\Content\Articles;
use Edict\Content\Slugs;
use Edict\Content\StaleDraft;
use Edict\Tests\DbTestCase;
use Psr\Http\Message\ResponseInterface;

final class ArticleFlowTest extends DbTestCase
{
    private const SESSION = 'edict_session';

    /** @var array<string, string> */
    private array $admin;
    private string $csrf;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service(AdminAccount::class)->create('sovereign', 'correct horse battery');

        $id = $this->pdo->query('SELECT id FROM app_user WHERE is_admin');
        self::assertNotFalse($id);
        $sessions = $this->service(SessionStore::class);
        // Long enough to outlive the clock jumps in these tests.
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
        /** @var array<string, string> $fields */
        return $this->post($path, $fields + ['_csrf' => $this->csrf], $this->admin);
    }

    private function newRow(string $kind = 'article'): int
    {
        $response = $this->adminPost('/admin/articles', ['kind' => $kind]);
        self::assertSame(303, $response->getStatusCode(), (string) $response->getBody());
        $id = substr($response->getHeaderLine('Location'), strlen('/admin/articles/'));
        self::assertTrue(ctype_digit($id));

        return (int) $id;
    }

    /**
     * @return array<string, mixed>
     */
    private static function defaults(): array
    {
        return [
            'title' => 'Hello World',
            'slug' => '',
            'lang' => 'en',
            'teaser' => 'A teaser',
            'body' => json_decode(Doc::text("First paragraph\n\nSecond paragraph"), true),
            'tags' => 'PHP, Web',
        ];
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function save(int $id, array $fields = []): ResponseInterface
    {
        $version = $this->service(Articles::class)->find($id)?->draftVersion;

        return $this->adminPost("/admin/articles/$id/autosave", ['version' => $version, 'writer' => 'test-tab', 'draft' => $fields + self::defaults()]);
    }

    private function publish(int $id): ResponseInterface
    {
        $version = $this->service(Articles::class)->find($id)?->draftVersion;

        return $this->adminPost("/admin/articles/$id/publish", ['version' => $version]);
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function saved(array $fields = [], string $kind = 'article'): int
    {
        $id = $this->newRow($kind);
        $response = $this->save($id, $fields);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        return $id;
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function published(array $fields = [], string $kind = 'article'): int
    {
        $id = $this->saved($fields, $kind);
        $response = $this->publish($id);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        return $id;
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function republish(int $id, array $fields): ResponseInterface
    {
        self::assertSame(200, $this->save($id, $fields)->getStatusCode());

        return $this->publish($id);
    }

    private function rows(string $table): int
    {
        $stmt = $this->pdo->query("SELECT count(*) FROM $table");
        self::assertNotFalse($stmt);

        return (int) $stmt->fetchColumn();
    }

    private function imageRow(string $kind = 'image'): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO media (sha256, kind, extension, bytes, original_name, width, height, status, created_at)
             VALUES (?, ?, 'jpg', 1000, 'x.jpg', 1600, 900, 'ready', now()) RETURNING id",
        );
        $stmt->execute([hash('sha256', random_bytes(8)), $kind]);
        $id = (int) $stmt->fetchColumn();
        $insert = $this->pdo->prepare('INSERT INTO media_variant (media_id, preset, format, width, height, bytes) VALUES (?, ?, ?, ?, ?, 10)');
        foreach (['avif', 'webp', 'jpg'] as $format) {
            $insert->execute([$id, "w480-$format", $format, 480, 270]);
            $insert->execute([$id, "w960-$format", $format, 960, 540]);
        }
        $insert->execute([$id, 'full', 'jpg', 1600, 900]);

        return $id;
    }

    private function coverOf(int $articleId): ?int
    {
        $stmt = $this->pdo->prepare('SELECT cover_media_id FROM article WHERE id = ?');
        $stmt->execute([$articleId]);
        $cover = $stmt->fetchColumn();

        return $cover === null ? null : (int) (is_numeric($cover) ? $cover : 0);
    }

    public function testTheCoverGoesLiveWithPublishing(): void
    {
        $cover = $this->imageRow();
        $id = $this->published();
        self::assertSame(200, $this->save($id, ['cover' => (string) $cover, 'coverAlt' => 'A <view>'])->getStatusCode());

        self::assertStringNotContainsString('article-cover', (string) $this->get('/hello-world')->getBody(), 'still a draft');
        $preview = (string) $this->get("/admin/articles/$id/preview", $this->admin)->getBody();
        self::assertStringContainsString('<figure class="article-cover">', $preview);
        $editor = (string) $this->get("/admin/articles/$id", $this->admin)->getBody();
        self::assertStringContainsString('<input type="hidden" id="cover" value="' . $cover . '">', $editor);
        self::assertStringContainsString('value="A &lt;view&gt;"', $editor);

        self::assertSame(200, $this->publish($id)->getStatusCode());

        $page = (string) $this->get('/hello-world')->getBody();
        self::assertStringContainsString('<figure class="article-cover">', $page);
        self::assertStringContainsString('alt="A &lt;view&gt;" fetchpriority="high"', $page, 'the first thing seen is not lazy');
        self::assertLessThan(strpos($page, 'class="teaser"'), strpos($page, 'article-cover'));

        $home = (string) $this->get('/')->getBody();
        self::assertStringContainsString('<div class="article-cover-thumb">', $home);
        self::assertStringContainsString('alt="" loading="lazy"', $home, 'beside the title it is decoration');
        self::assertStringNotContainsString('lightbox', $home);
    }

    public function testACoverThatIsNotAnImageIsNone(): void
    {
        $id = $this->published(['cover' => '999999']);
        self::assertNull($this->coverOf($id), 'a missing picture is no cover');

        $video = $this->imageRow('video');
        self::assertSame(200, $this->republish($id, ['cover' => $video])->getStatusCode());
        self::assertNull($this->coverOf($id));

        self::assertSame(200, $this->save($id, ['cover' => '1; DROP TABLE article'])->getStatusCode());
        self::assertNull($this->service(Articles::class)->find($id)?->input()->cover);
    }

    public function testDeletingTheCoverImageLeavesTheArticle(): void
    {
        $cover = $this->imageRow();
        $id = $this->published(['cover' => (string) $cover]);
        self::assertSame($cover, $this->coverOf($id));

        $this->pdo->prepare('DELETE FROM media WHERE id = ?')->execute([$cover]);

        self::assertNull($this->coverOf($id));
        self::assertSame(200, $this->get('/hello-world')->getStatusCode());
    }

    public function testATooLongCoverDescriptionIsReported(): void
    {
        $id = $this->saved(['coverAlt' => str_repeat('x', 501)]);

        $response = $this->publish($id);

        self::assertSame(422, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertIsArray($data);
        self::assertIsArray($data['errors']);
        self::assertSame(['coverAlt'], array_keys($data['errors']));
    }

    public function testEveryApplicationPathIsReservedFromSlugs(): void
    {
        foreach ($this->app->getRouteCollector()->getRoutes() as $route) {
            $first = explode('/', $route->getPattern())[1] ?? '';
            if ($first !== '' && !str_starts_with($first, '{')) {
                self::assertTrue(Slugs::isReserved($first), "/$first must be reserved");
            }
        }
    }

    public function testEmptyHomePage(): void
    {
        $response = $this->get('/');

        self::assertSame(200, $response->getStatusCode());
        self::assertStringStartsWith('text/html', $response->getHeaderLine('Content-Type'));
        self::assertStringContainsString('<h1>Hello, Edict</h1>', (string) $response->getBody());
        self::assertStringContainsString('Nothing to read yet', (string) $response->getBody());
    }

    public function testUnknownSlugIs404(): void
    {
        self::assertSame(404, $this->get('/no-such-page')->getStatusCode());
    }

    public function testNewRowOpensABlankEditor(): void
    {
        $id = $this->newRow();

        $page = $this->get("/admin/articles/$id", $this->admin);

        self::assertSame(200, $page->getStatusCode());
        $body = (string) $page->getBody();
        self::assertStringContainsString('id="editor-app"', $body);
        self::assertStringContainsString('id="title" class="editor-title" value=""', $body);
        self::assertStringContainsString('/assets/build/editor.js', $body);
        self::assertSame('no-store', $page->getHeaderLine('Cache-Control'));
        self::assertStringContainsString('Untitled', (string) $this->get('/admin/articles', $this->admin)->getBody());
    }

    public function testDraftIsNotPublic(): void
    {
        $this->saved();
        $untitled = $this->service(Articles::class)->adminList('article', false)[0]->slug;

        foreach (["/$untitled", '/hello-world'] as $path) {
            self::assertSame(404, $this->get($path)->getStatusCode(), $path);
        }
        foreach (['/', '/archive'] as $path) {
            self::assertStringNotContainsString('Hello World', (string) $this->get($path)->getBody(), $path);
        }
    }

    public function testPublishedArticleAppearsEverywhere(): void
    {
        $this->published();

        $article = $this->get('/hello-world');
        self::assertSame(200, $article->getStatusCode());
        $body = (string) $article->getBody();
        self::assertStringContainsString('<article lang="en">', $body);
        self::assertStringContainsString('<p>First paragraph</p>', $body);
        self::assertStringContainsString('href="/tag/php"', $body);
        self::assertStringContainsString('<title>Hello World · Edict</title>', $body);

        foreach (['/', '/archive', '/tag/php'] as $path) {
            self::assertStringContainsString('href="/hello-world"', (string) $this->get($path)->getBody(), $path);
        }
    }

    public function testArticleUsesItsLanguageOnThePage(): void
    {
        $this->published(['lang' => 'de', 'title' => 'Grüße']);

        $body = (string) $this->get('/gruesse')->getBody();

        self::assertStringContainsString('<html lang="en">', $body);
        self::assertStringContainsString('<article lang="de">', $body);
        self::assertStringContainsString('. Januar 2027', $body);
    }

    public function testFieldsAreEscaped(): void
    {
        $this->published([
            'title' => '<script>a</script>',
            'slug' => 'escaped',
            'teaser' => '<script>b</script>',
            'body' => json_decode(Doc::text('<script>c</script>'), true),
            'tags' => '<script>d</script>',
        ]);

        foreach (['/escaped', '/', '/admin/articles'] as $path) {
            self::assertStringNotContainsString('<script>', (string) $this->get($path, $this->admin)->getBody(), $path);
        }
    }

    public function testAutosaveStoresOnlyCleanContent(): void
    {
        $id = $this->saved(['body' => ['type' => 'doc', 'content' => [
            ['type' => 'rawHtml', 'html' => '<script>x</script>'],
            ['type' => 'paragraph', 'attrs' => ['onclick' => 'x'], 'content' => [['type' => 'text', 'text' => 'kept']]],
        ]]]);

        $draft = $this->service(Articles::class)->find($id)?->draftJson;

        self::assertIsString($draft);
        self::assertStringContainsString('kept', $draft);
        self::assertStringNotContainsString('script', $draft);
        self::assertStringNotContainsString('onclick', $draft);
    }

    public function testAutosaveKeepsIncompleteDrafts(): void
    {
        $id = $this->newRow();

        $response = $this->save($id, ['title' => '', 'slug' => 'Not A Valid Slug', 'lang' => '']);

        self::assertSame(200, $response->getStatusCode());
        $input = $this->service(Articles::class)->find($id)?->input();
        self::assertSame('Not A Valid Slug', $input?->slug);
    }

    public function testAutosaveRefusesAStaleVersion(): void
    {
        $id = $this->newRow();
        $version = $this->service(Articles::class)->find($id)?->draftVersion;
        $this->save($id, ['title' => 'Newer']);

        $stale = $this->adminPost("/admin/articles/$id/autosave", ['version' => $version, 'writer' => 'other-tab', 'draft' => ['title' => 'Older'] + self::defaults()]);

        self::assertSame(409, $stale->getStatusCode());
        self::assertSame('Newer', $this->service(Articles::class)->find($id)?->input()->title);
    }

    public function testTheSameEditorCanRepeatASaveWhoseAnswerGotLost(): void
    {
        $id = $this->newRow();
        $version = $this->service(Articles::class)->find($id)?->draftVersion;
        $this->save($id, ['title' => 'Committed, answer lost']);

        $repeat = $this->adminPost("/admin/articles/$id/autosave", ['version' => $version, 'writer' => 'test-tab', 'draft' => ['title' => 'Repeated'] + self::defaults()]);

        self::assertSame(200, $repeat->getStatusCode());
        self::assertSame('Repeated', $this->service(Articles::class)->find($id)?->input()->title);
    }

    public function testASaveAfterAnotherEditorIsRefusedEvenForAnEarlierWriter(): void
    {
        $id = $this->newRow();
        $version = $this->service(Articles::class)->find($id)?->draftVersion;
        $this->save($id, ['title' => 'Mine']);
        $other = $this->service(Articles::class)->saveDraft($id, (int) $version + 1, 'other-tab', '{"title":"Theirs"}');
        self::assertNotNull($other);

        $mine = $this->adminPost("/admin/articles/$id/autosave", ['version' => $version, 'writer' => 'test-tab', 'draft' => ['title' => 'Mine again'] + self::defaults()]);

        self::assertSame(409, $mine->getStatusCode());
        self::assertSame('Theirs', $this->service(Articles::class)->find($id)?->input()->title);
    }

    public function testASaveDuringPublishingIsNotWiped(): void
    {
        $id = $this->saved(['title' => 'Going live']);
        $articles = $this->service(Articles::class);
        $read = $articles->find($id);
        self::assertNotNull($read);

        // Autosave commits after the publish request read the article.
        $articles->saveDraft($id, $read->draftVersion, 'test-tab', '{"title":"Typed meanwhile"}');

        try {
            $articles->publish($read, $read->input());
            self::fail('Published over newer text');
        } catch (StaleDraft) {
            $this->addToAssertionCount(1);
        }

        $after = $articles->find($id);
        self::assertNotNull($after);
        self::assertSame('Typed meanwhile', $after->input()->title);
        self::assertSame('draft', $after->status);
        self::assertSame(404, $this->get('/going-live')->getStatusCode());
    }

    public function testTooDeeplyNestedTextIsRefusedNotCut(): void
    {
        $id = $this->saved(['title' => 'Kept']);
        $node = ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'deep']]];
        for ($i = 0; $i < 120; ++$i) {
            $node = ['type' => 'blockquote', 'content' => [$node]];
        }

        $response = $this->save($id, ['title' => 'Replaced', 'body' => ['type' => 'doc', 'content' => [$node]]]);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('Kept', $this->service(Articles::class)->find($id)?->input()->title);
    }

    public function testAGalleryTooLongIsRefusedNotCut(): void
    {
        $id = $this->saved(['title' => 'Kept']);
        $items = array_map(static fn(int $i): array => ['mediaId' => $i, 'alt' => ''], range(1, 101));

        $response = $this->save($id, ['title' => 'Replaced', 'body' => ['type' => 'doc', 'content' => [['type' => 'gallery', 'attrs' => ['items' => $items]]]]]);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('Kept', $this->service(Articles::class)->find($id)?->input()->title);
    }

    public function testAutosaveReportsItsNewVersion(): void
    {
        $id = $this->newRow();
        $before = $this->service(Articles::class)->find($id)?->draftVersion;

        $response = $this->save($id);

        $data = json_decode((string) $response->getBody(), true);
        self::assertIsArray($data);
        self::assertSame($before + 1, $data['version']);
    }

    public function testMalformedSaveNeverWipesTheDraft(): void
    {
        $id = $this->saved(['title' => 'Precious']);
        $version = $this->service(Articles::class)->find($id)?->draftVersion;

        foreach ([
            [],
            ['version' => $version, 'writer' => 'tab'],
            ['writer' => 'tab', 'draft' => self::defaults()],
            ['version' => $version, 'draft' => self::defaults()],
            ['version' => $version, 'writer' => 'tab', 'draft' => 'nonsense'],
            ['version' => (string) $version, 'writer' => 'tab', 'draft' => self::defaults()],
            ['version' => $version, 'writer' => 'not valid!', 'draft' => self::defaults()],
        ] as $payload) {
            self::assertSame(400, $this->adminPost("/admin/articles/$id/autosave", $payload)->getStatusCode());
        }

        self::assertSame('Precious', $this->service(Articles::class)->find($id)?->input()->title);
    }

    public function testOversizedDraftIsRefused(): void
    {
        $id = $this->newRow();

        $response = $this->save($id, ['teaser' => str_repeat('x', 4_000_001)]);

        self::assertSame(413, $response->getStatusCode());
    }

    public function testAutosaveInTheTrashIsRefused(): void
    {
        $id = $this->saved(['title' => 'Before']);
        $this->adminPost("/admin/articles/$id/trash");

        self::assertSame(409, $this->save($id, ['title' => 'After'])->getStatusCode());
    }

    public function testEditingAPublishedArticleStaysPrivateUntilPublished(): void
    {
        $id = $this->published();

        $this->save($id, ['body' => json_decode(Doc::text('Half-finished thought'), true), 'title' => 'New title']);

        $public = (string) $this->get('/hello-world')->getBody();
        self::assertStringNotContainsString('Half-finished', $public);
        self::assertStringContainsString('Hello World', $public);

        $article = $this->service(Articles::class)->find($id);
        self::assertNotNull($article);
        self::assertTrue($article->hasPendingChanges());
        self::assertStringContainsString('changes pending', (string) $this->get('/admin/articles', $this->admin)->getBody());

        $preview = (string) $this->get("/admin/articles/$id/preview", $this->admin)->getBody();
        self::assertStringContainsString('Half-finished', $preview);
        self::assertStringContainsString('New title', $preview);

        self::assertSame(200, $this->publish($id)->getStatusCode());

        $public = (string) $this->get('/hello-world')->getBody();
        self::assertStringContainsString('Half-finished', $public);
        self::assertStringContainsString('New title', $public);
        self::assertFalse($this->service(Articles::class)->find($id)?->hasPendingChanges());
    }

    public function testPublishRefusesAStaleVersion(): void
    {
        $id = $this->saved();

        $response = $this->adminPost("/admin/articles/$id/publish", ['version' => 0]);

        self::assertSame(409, $response->getStatusCode());
        self::assertSame(404, $this->get('/hello-world')->getStatusCode());
    }

    public function testPublishReportsWhatIsWrongAndKeepsTheDraft(): void
    {
        $id = $this->saved(['title' => '', 'slug' => 'admin', 'lang' => 'fr']);

        $response = $this->publish($id);

        self::assertSame(422, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertIsArray($data);
        self::assertIsArray($data['errors']);
        self::assertSame(['title', 'slug', 'lang'], array_keys($data['errors']));
        $article = $this->service(Articles::class)->find($id);
        self::assertNotNull($article);
        self::assertSame('draft', $article->status);
        self::assertSame('admin', $article->input()->slug);
    }

    public function testUnpublishHidesAndRepublishKeepsTheDate(): void
    {
        $id = $this->published();
        $first = $this->service(Articles::class)->find($id)?->publishedAt;

        $this->adminPost("/admin/articles/$id/unpublish");
        self::assertSame(404, $this->get('/hello-world')->getStatusCode());

        $this->clock->advance(86400);
        $this->publish($id);

        self::assertSame(200, $this->get('/hello-world')->getStatusCode());
        self::assertSame($first, $this->service(Articles::class)->find($id)?->publishedAt);
    }

    public function testNewestComesFirstAndPagesWork(): void
    {
        for ($i = 1; $i <= 21; ++$i) {
            $this->published(['title' => "Article $i", 'tags' => 'all']);
            $this->clock->advance(60);
        }

        foreach (['/archive', '/tag/all'] as $path) {
            $first = (string) $this->get($path)->getBody();
            self::assertLessThan(strpos($first, '"/article-20"'), strpos($first, '"/article-21"'), $path);
            self::assertStringNotContainsString('"/article-1"', $first, $path);
            self::assertStringContainsString("$path?page=2", $first, $path);

            $second = (string) $this->get("$path?page=2")->getBody();
            self::assertStringContainsString('"/article-1"', $second, $path);
            self::assertStringContainsString('Page 2 of 2', $second, $path);

            foreach (['3', '0', '-1', 'x', '1.5', '99999999999999999999'] as $page) {
                self::assertSame(404, $this->get("$path?page=$page")->getStatusCode(), "$path $page");
            }
        }

        $home = (string) $this->get('/')->getBody();
        self::assertStringContainsString('"/article-12"', $home);
        self::assertStringNotContainsString('"/article-11"', $home);
        self::assertStringContainsString('All articles', $home);
    }

    public function testUnknownTagIs404AndDraftOnlyTagToo(): void
    {
        $this->published(['tags' => 'visible']);
        $hidden = $this->published(['title' => 'Hidden', 'tags' => 'hidden']);
        $this->adminPost("/admin/articles/$hidden/unpublish");

        self::assertSame(404, $this->get('/tag/nothing')->getStatusCode());
        self::assertSame(404, $this->get('/tag/hidden')->getStatusCode());
        self::assertSame(200, $this->get('/tag/visible')->getStatusCode());
    }

    public function testEmptyArchiveExists(): void
    {
        self::assertSame(200, $this->get('/archive')->getStatusCode());
    }

    public function testSlugsAreDerivedOnFirstPublishAndMadeUnique(): void
    {
        foreach (['hello-world', 'hello-world-2', 'hello-world-3'] as $slug) {
            $id = $this->published();
            self::assertSame($slug, $this->service(Articles::class)->find($id)?->slug);
        }
    }

    public function testDerivedSlugAvoidsReservedAndEmpty(): void
    {
        $articles = $this->service(Articles::class);

        self::assertSame('admin-article', $articles->find($this->published(['title' => 'Admin']))?->slug);
        self::assertSame('article', $articles->find($this->published(['title' => '???']))?->slug);
    }

    public function testRenamingAPublishedArticleRedirects(): void
    {
        $id = $this->published();

        self::assertSame(200, $this->republish($id, ['slug' => 'second'])->getStatusCode());
        self::assertSame(200, $this->republish($id, ['slug' => 'third'])->getStatusCode());

        foreach (['/hello-world', '/second'] as $old) {
            $response = $this->get($old);
            self::assertSame(301, $response->getStatusCode(), $old);
            self::assertSame('/third', $response->getHeaderLine('Location'));
        }
        self::assertSame(200, $this->get('/third')->getStatusCode());
    }

    public function testFirstPublishLeavesNoRedirectFromThePlaceholderSlug(): void
    {
        $this->published();

        self::assertSame(0, $this->rows('slug_redirect'));
    }

    public function testRenamingBackRemovesTheRedirect(): void
    {
        $id = $this->published();
        $this->republish($id, ['slug' => 'other']);

        $this->republish($id, ['slug' => 'hello-world']);

        self::assertSame(200, $this->get('/hello-world')->getStatusCode());
        self::assertSame(301, $this->get('/other')->getStatusCode());
        self::assertSame(1, $this->rows('slug_redirect'));
    }

    public function testEmptySlugKeepsAPublishedAddress(): void
    {
        $id = $this->published();

        $this->republish($id, ['title' => 'Entirely new title']);

        self::assertSame('hello-world', $this->service(Articles::class)->find($id)?->slug);
    }

    public function testSlugConflictIsReportedAndTheTextStays(): void
    {
        $this->published(['slug' => 'taken']);
        $id = $this->saved(['title' => 'My long text', 'slug' => 'taken']);

        $response = $this->publish($id);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('already in use', (string) $response->getBody());
        self::assertSame('My long text', $this->service(Articles::class)->find($id)?->input()->title);
    }

    public function testAnotherArticleCannotTakeAnOldSlug(): void
    {
        $this->republish($this->published(), ['slug' => 'moved']);

        self::assertSame(422, $this->publish($this->saved(['title' => 'New', 'slug' => 'hello-world']))->getStatusCode());

        $derived = $this->published(['title' => 'Hello World']);
        self::assertSame('hello-world-2', $this->service(Articles::class)->find($derived)?->slug);
    }

    public function testRedirectNeverRevealsAHiddenArticle(): void
    {
        $id = $this->published();
        $this->republish($id, ['slug' => 'secret-new-name']);
        $this->adminPost("/admin/articles/$id/unpublish");

        $response = $this->get('/hello-world');

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('', $response->getHeaderLine('Location'));
    }

    public function testTagsAreReplacedAndOrphansRemoved(): void
    {
        $id = $this->published(['tags' => 'one, two']);
        self::assertSame(2, $this->rows('tag'));

        $this->republish($id, ['tags' => 'two, three']);

        $stmt = $this->pdo->query('SELECT slug FROM tag ORDER BY slug');
        self::assertNotFalse($stmt);
        self::assertSame(['three', 'two'], $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    public function testEditorShowsSavedValuesAgain(): void
    {
        $id = $this->saved();

        $body = (string) $this->get("/admin/articles/$id", $this->admin)->getBody();

        self::assertStringContainsString('value="Hello World"', $body);
        self::assertStringContainsString('First paragraph', $body);
        self::assertStringContainsString('value="PHP, Web"', $body);
    }

    public function testEditorOpensPublishedArticleWithItsLiveContent(): void
    {
        $id = $this->published();

        $body = (string) $this->get("/admin/articles/$id", $this->admin)->getBody();

        self::assertStringContainsString('value="Hello World"', $body);
        self::assertStringContainsString('value="hello-world"', $body);
        self::assertStringContainsString('First paragraph', $body);
        self::assertStringContainsString('>Update<', $body);
    }

    public function testNewRowDefaultsToTheLastLanguage(): void
    {
        $first = $this->newRow();
        self::assertSame('de', $this->service(Articles::class)->find($first)?->input()->lang);

        $this->published(['lang' => 'en']);

        self::assertSame('en', $this->service(Articles::class)->find($this->newRow())?->input()->lang);
    }

    public function testPreviewShowsDraftToAdminOnly(): void
    {
        $id = $this->saved();

        $preview = $this->get("/admin/articles/$id/preview", $this->admin);
        self::assertSame(200, $preview->getStatusCode());
        self::assertStringContainsString('<p>First paragraph</p>', (string) $preview->getBody());
        self::assertSame('noindex', $preview->getHeaderLine('X-Robots-Tag'));
        self::assertSame('no-store', $preview->getHeaderLine('Cache-Control'));

        self::assertSame(303, $this->get("/admin/articles/$id/preview")->getStatusCode());
    }

    public function testPreviewOfABlankDraftWorks(): void
    {
        $id = $this->newRow();

        self::assertSame(200, $this->get("/admin/articles/$id/preview", $this->admin)->getStatusCode());
    }

    public function testAdminRoutesNeedAdminAndCsrf(): void
    {
        $id = $this->saved();

        foreach (['/admin/articles', "/admin/articles/$id", '/admin/csrf'] as $path) {
            self::assertSame(303, $this->get($path)->getStatusCode(), $path);
        }
        self::assertSame(303, $this->post("/admin/articles/$id/publish")->getStatusCode());
        self::assertSame(303, $this->post("/admin/articles/$id/autosave")->getStatusCode());

        foreach (['/admin/articles', "/admin/articles/$id/autosave", "/admin/articles/$id/publish", "/admin/articles/$id/trash"] as $path) {
            self::assertSame(403, $this->post($path, ['title' => 'x'], $this->admin)->getStatusCode(), $path);
        }
        self::assertSame(404, $this->get('/hello-world')->getStatusCode());
    }

    public function testCsrfTokenIsServedToTheSessionOnly(): void
    {
        $response = $this->get('/admin/csrf', $this->admin);

        self::assertSame((string) json_encode(['csrf' => $this->csrf]), (string) $response->getBody());
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
    }

    public function testReaderCannotUseAdminRoutes(): void
    {
        $insert = $this->pdo->query("INSERT INTO app_user (display_name) VALUES ('reader') RETURNING id");
        self::assertNotFalse($insert);
        $token = $this->service(SessionStore::class)->create((int) $insert->fetchColumn(), 3600);

        self::assertSame(403, $this->get('/admin/articles', [self::SESSION => $token])->getStatusCode());
    }

    public function testUnknownIdIs404(): void
    {
        self::assertSame(404, $this->get('/admin/articles/999', $this->admin)->getStatusCode());
        self::assertSame(404, $this->adminPost('/admin/articles/999/autosave', ['version' => 0, 'writer' => 'tab', 'draft' => []])->getStatusCode());
    }

    public function testStaticPagesAreReachableButNotListed(): void
    {
        $this->published(['title' => 'Impressum', 'tags' => 'hidden'], 'page');

        $page = (string) $this->get('/impressum')->getBody();
        self::assertStringContainsString('<h1>Impressum</h1>', $page);
        self::assertStringNotContainsString('<time', $page);
        self::assertStringNotContainsString('/tag/', $page);

        foreach (['/', '/archive'] as $path) {
            self::assertStringNotContainsString('Impressum', (string) $this->get($path)->getBody(), $path);
        }
        self::assertSame(404, $this->get('/tag/hidden')->getStatusCode());

        self::assertStringContainsString('Impressum', (string) $this->get('/admin/articles?kind=page', $this->admin)->getBody());
        self::assertStringNotContainsString('Impressum', (string) $this->get('/admin/articles', $this->admin)->getBody());
    }

    public function testPagesAndArticlesShareOneNamespace(): void
    {
        $this->published(['title' => 'About'], 'page');

        $id = $this->published(['title' => 'About']);

        self::assertSame('about-2', $this->service(Articles::class)->find($id)?->slug);
    }

    public function testTrashRestoreAndPermanentDelete(): void
    {
        $id = $this->published();

        $this->adminPost("/admin/articles/$id/trash");
        self::assertSame(404, $this->get('/hello-world')->getStatusCode());
        self::assertStringNotContainsString('Hello World', (string) $this->get('/admin/articles', $this->admin)->getBody());
        self::assertStringContainsString('Hello World', (string) $this->get('/admin/articles?trash=1', $this->admin)->getBody());

        $this->adminPost("/admin/articles/$id/restore");
        self::assertSame(200, $this->get('/hello-world')->getStatusCode());

        // Permanent delete is only possible from the trash, and needs the confirmation.
        $this->adminPost("/admin/articles/$id/delete", ['confirm' => 'yes']);
        $notInTrash = $this->rows('article');
        self::assertSame(1, $notInTrash);

        $this->adminPost("/admin/articles/$id/trash");
        $this->adminPost("/admin/articles/$id/delete");
        $withoutConfirmation = $this->rows('article');
        self::assertSame(1, $withoutConfirmation);

        $this->adminPost("/admin/articles/$id/delete", ['confirm' => 'yes']);
        self::assertSame(0, $this->rows('article'));
        self::assertSame(0, $this->rows('tag'));
    }

    public function testTrashedArticleCannotBePublished(): void
    {
        $id = $this->saved();
        $this->adminPost("/admin/articles/$id/trash");

        self::assertSame(409, $this->publish($id)->getStatusCode());
        self::assertSame('draft', $this->service(Articles::class)->find($id)?->status);
    }

    public function testTrashEmptiesItselfAfterThirtyDays(): void
    {
        $id = $this->newRow();
        $this->adminPost("/admin/articles/$id/trash");

        $this->clock->advance(Articles::TRASH_DAYS * 86400 - 60);
        $this->get('/admin/articles?trash=1', $this->admin);
        $beforeTheLimit = $this->rows('article');
        self::assertSame(1, $beforeTheLimit);

        $this->clock->advance(61);
        $this->get('/admin/articles?trash=1', $this->admin);
        self::assertSame(0, $this->rows('article'));
    }

    public function testHomeLinksFollowTheSession(): void
    {
        self::assertStringContainsString('href="/login"', (string) $this->get('/')->getBody());
        self::assertStringContainsString('href="/account"', (string) $this->get('/', $this->admin)->getBody());
    }
}
