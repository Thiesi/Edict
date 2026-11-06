<?php

declare(strict_types=1);

namespace Edict\Tests\Content;

use Edict\Auth\AdminAccount;
use Edict\Auth\SessionStore;
use Edict\Content\Articles;
use Edict\Content\Slugs;
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
        $token = $sessions->create((int) $id->fetchColumn(), 3600);
        $session = $sessions->load($token);
        self::assertNotNull($session);
        $this->admin = [self::SESSION => $token];
        $this->csrf = $session->csrfToken;
    }

    /**
     * @param array<string, string> $fields
     */
    private function adminPost(string $path, array $fields = []): ResponseInterface
    {
        return $this->post($path, $fields + ['_csrf' => $this->csrf], $this->admin);
    }

    /**
     * @param array<string, string> $fields
     */
    private function createArticle(array $fields = []): int
    {
        $response = $this->adminPost('/admin/articles', $fields + [
            'title' => 'Hello World',
            'slug' => '',
            'lang' => 'en',
            'teaser' => 'A teaser',
            'body' => "First paragraph\n\nSecond paragraph",
            'tags' => 'PHP, Web',
        ]);
        self::assertSame(303, $response->getStatusCode(), (string) $response->getBody());
        $id = substr($response->getHeaderLine('Location'), strlen('/admin/articles/'));
        self::assertTrue(ctype_digit($id));

        return (int) $id;
    }

    /**
     * @param array<string, string> $fields
     */
    private function publishedArticle(array $fields = []): int
    {
        $id = $this->createArticle($fields);
        $this->adminPost("/admin/articles/$id/publish");

        return $id;
    }

    /**
     * @param array<string, string> $fields
     */
    private function updateArticle(int $id, array $fields): ResponseInterface
    {
        return $this->adminPost("/admin/articles/$id", $fields + [
            'title' => 'Hello World',
            'slug' => '',
            'lang' => 'en',
            'teaser' => '',
            'body' => 'Text',
            'tags' => '',
        ]);
    }

    private function rows(string $table): int
    {
        $stmt = $this->pdo->query("SELECT count(*) FROM $table");
        self::assertNotFalse($stmt);

        return (int) $stmt->fetchColumn();
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

    public function testDraftIsNotPublic(): void
    {
        $this->createArticle();

        self::assertSame(404, $this->get('/hello-world')->getStatusCode());
        self::assertStringNotContainsString('Hello World', (string) $this->get('/')->getBody());
        self::assertStringNotContainsString('Hello World', (string) $this->get('/archive')->getBody());
    }

    public function testPublishedArticleAppearsEverywhere(): void
    {
        $this->publishedArticle();

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
        $this->publishedArticle(['lang' => 'de', 'title' => 'Grüße']);

        $body = (string) $this->get('/gruesse')->getBody();

        self::assertStringContainsString('<html lang="en">', $body);
        self::assertStringContainsString('<article lang="de">', $body);
        self::assertStringContainsString('. Januar 2027', $body);
    }

    public function testBodyAndFieldsAreEscaped(): void
    {
        $this->publishedArticle([
            'title' => '<script>a</script>',
            'slug' => 'escaped',
            'teaser' => '<script>b</script>',
            'body' => '<script>c</script>',
            'tags' => '<script>d</script>',
        ]);

        foreach (['/escaped', '/'] as $path) {
            self::assertStringNotContainsString('<script>', (string) $this->get($path)->getBody(), $path);
        }
    }

    public function testUnpublishHidesAndRepublishKeepsTheDate(): void
    {
        $id = $this->publishedArticle();
        $first = $this->service(Articles::class)->find($id)?->publishedAt;

        $this->adminPost("/admin/articles/$id/unpublish");
        self::assertSame(404, $this->get('/hello-world')->getStatusCode());

        $this->clock->advance(86400);
        $this->adminPost("/admin/articles/$id/publish");

        self::assertSame(200, $this->get('/hello-world')->getStatusCode());
        self::assertSame($first, $this->service(Articles::class)->find($id)?->publishedAt);
    }

    public function testNewestComesFirstAndPagesWork(): void
    {
        for ($i = 1; $i <= 21; ++$i) {
            $this->publishedArticle(['title' => "Article $i", 'tags' => 'all']);
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
        $this->createArticle(['tags' => 'hidden']);

        self::assertSame(404, $this->get('/tag/nothing')->getStatusCode());
        self::assertSame(404, $this->get('/tag/hidden')->getStatusCode());
    }

    public function testEmptyArchiveExists(): void
    {
        self::assertSame(200, $this->get('/archive')->getStatusCode());
    }

    public function testSlugsAreDerivedAndMadeUnique(): void
    {
        foreach (['hello-world', 'hello-world-2', 'hello-world-3'] as $slug) {
            $id = $this->createArticle();
            self::assertSame($slug, $this->service(Articles::class)->find($id)?->slug);
        }
    }

    public function testDerivedSlugAvoidsReservedAndEmpty(): void
    {
        $articles = $this->service(Articles::class);

        self::assertSame('admin-article', $articles->find($this->createArticle(['title' => 'Admin']))?->slug);
        self::assertSame('article', $articles->find($this->createArticle(['title' => '???']))?->slug);
    }

    public function testExplicitSlugConflictsKeepWhatWasTyped(): void
    {
        $this->createArticle(['slug' => 'taken']);

        $response = $this->adminPost('/admin/articles', ['title' => 'My long text', 'slug' => 'taken', 'lang' => 'en', 'body' => 'Typed words']);

        self::assertSame(422, $response->getStatusCode());
        $body = (string) $response->getBody();
        self::assertStringContainsString('already in use', $body);
        self::assertStringContainsString('My long text', $body);
        self::assertStringContainsString('Typed words', $body);
        self::assertSame(1, $this->rows('article'));
    }

    public function testRenamingAPublishedArticleRedirects(): void
    {
        $id = $this->publishedArticle();

        self::assertSame(303, $this->updateArticle($id, ['slug' => 'second'])->getStatusCode());
        self::assertSame(303, $this->updateArticle($id, ['slug' => 'third'])->getStatusCode());

        foreach (['/hello-world', '/second'] as $old) {
            $response = $this->get($old);
            self::assertSame(301, $response->getStatusCode(), $old);
            self::assertSame('/third', $response->getHeaderLine('Location'));
        }
        self::assertSame(200, $this->get('/third')->getStatusCode());
    }

    public function testRenamingADraftLeavesNoRedirect(): void
    {
        $id = $this->createArticle();

        $this->updateArticle($id, ['slug' => 'other']);

        self::assertSame(0, $this->rows('slug_redirect'));
    }

    public function testRenamingBackRemovesTheRedirect(): void
    {
        $id = $this->publishedArticle();
        $this->updateArticle($id, ['slug' => 'other']);

        $this->updateArticle($id, ['slug' => 'hello-world']);

        self::assertSame(200, $this->get('/hello-world')->getStatusCode());
        self::assertSame(301, $this->get('/other')->getStatusCode());
        self::assertSame(1, $this->rows('slug_redirect'));
    }

    public function testEmptySlugKeepsTheCurrentOne(): void
    {
        $id = $this->createArticle();

        $this->updateArticle($id, ['title' => 'Entirely new title']);

        self::assertSame('hello-world', $this->service(Articles::class)->find($id)?->slug);
    }

    public function testAnotherArticleCannotTakeAnOldSlug(): void
    {
        $this->updateArticle($this->publishedArticle(), ['slug' => 'moved']);

        $response = $this->adminPost('/admin/articles', ['title' => 'New', 'slug' => 'hello-world', 'lang' => 'en']);
        self::assertSame(422, $response->getStatusCode());

        $derived = $this->createArticle(['title' => 'Hello World']);
        self::assertSame('hello-world-2', $this->service(Articles::class)->find($derived)?->slug);
    }

    public function testRedirectNeverRevealsAHiddenArticle(): void
    {
        $id = $this->publishedArticle();
        $this->updateArticle($id, ['slug' => 'secret-new-name']);
        $this->adminPost("/admin/articles/$id/unpublish");

        $response = $this->get('/hello-world');

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('', $response->getHeaderLine('Location'));
    }

    public function testTagsAreReplacedAndOrphansRemoved(): void
    {
        $id = $this->createArticle(['tags' => 'one, two']);
        self::assertSame(2, $this->rows('tag'));

        $this->updateArticle($id, ['tags' => 'two, three']);

        $stmt = $this->pdo->query('SELECT slug FROM tag ORDER BY slug');
        self::assertNotFalse($stmt);
        self::assertSame(['three', 'two'], $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    public function testFormShowsSavedValuesAgain(): void
    {
        $id = $this->createArticle();

        $body = (string) $this->get("/admin/articles/$id", $this->admin)->getBody();

        self::assertStringContainsString('value="Hello World"', $body);
        self::assertStringContainsString("First paragraph\n\nSecond paragraph", $body);
        self::assertStringContainsString('value="PHP, Web"', $body);
    }

    public function testNewFormDefaultsToTheLastLanguage(): void
    {
        self::assertStringContainsString('<option value="de" selected>', (string) $this->get('/admin/articles/new', $this->admin)->getBody());

        $this->createArticle(['lang' => 'en']);

        self::assertStringContainsString('<option value="en" selected>', (string) $this->get('/admin/articles/new', $this->admin)->getBody());
    }

    public function testPreviewShowsDraftToAdminOnly(): void
    {
        $id = $this->createArticle();

        $preview = $this->get("/admin/articles/$id/preview", $this->admin);
        self::assertSame(200, $preview->getStatusCode());
        self::assertStringContainsString('<p>First paragraph</p>', (string) $preview->getBody());
        self::assertSame('noindex', $preview->getHeaderLine('X-Robots-Tag'));
        self::assertSame('no-store', $preview->getHeaderLine('Cache-Control'));

        self::assertSame(303, $this->get("/admin/articles/$id/preview")->getStatusCode());
    }

    public function testAdminRoutesNeedAdminAndCsrf(): void
    {
        $id = $this->createArticle();

        foreach (['/admin/articles', '/admin/articles/new', "/admin/articles/$id"] as $path) {
            self::assertSame(303, $this->get($path)->getStatusCode(), $path);
        }
        self::assertSame(303, $this->post("/admin/articles/$id/publish")->getStatusCode());

        foreach (['/admin/articles', "/admin/articles/$id", "/admin/articles/$id/publish", "/admin/articles/$id/trash"] as $path) {
            self::assertSame(403, $this->post($path, ['title' => 'x'], $this->admin)->getStatusCode(), $path);
        }
        self::assertSame(404, $this->get('/hello-world')->getStatusCode());
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
        self::assertSame(404, $this->adminPost('/admin/articles/999/publish')->getStatusCode());
    }

    public function testTrashRestoreAndPermanentDelete(): void
    {
        $id = $this->publishedArticle();

        $this->adminPost("/admin/articles/$id/trash");
        self::assertSame(404, $this->get('/hello-world')->getStatusCode());
        self::assertStringNotContainsString('Hello World', (string) $this->get('/admin/articles', $this->admin)->getBody());
        self::assertStringContainsString('Hello World', (string) $this->get('/admin/articles?trash=1', $this->admin)->getBody());

        $this->adminPost("/admin/articles/$id/restore");
        self::assertSame(200, $this->get('/hello-world')->getStatusCode());

        // Permanent delete is only possible from the trash, and needs the confirmation.
        $this->adminPost("/admin/articles/$id/delete", ['confirm' => 'yes']);
        self::assertSame(1, $this->rows('article'));

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
        $id = $this->createArticle();
        $this->adminPost("/admin/articles/$id/trash");

        $this->adminPost("/admin/articles/$id/publish");

        self::assertSame('draft', $this->service(Articles::class)->find($id)?->status);
    }

    public function testTrashEmptiesItselfAfterThirtyDays(): void
    {
        $id = $this->createArticle();
        $this->adminPost("/admin/articles/$id/trash");

        $this->clock->advance(Articles::TRASH_DAYS * 86400 - 60);
        $this->get('/admin/articles?trash=1', $this->admin);
        self::assertSame(1, $this->rows('article'));

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
