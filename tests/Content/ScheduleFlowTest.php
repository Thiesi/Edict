<?php

declare(strict_types=1);

namespace Edict\Tests\Content;

use Edict\Auth\AdminAccount;
use Edict\Auth\SessionStore;
use Edict\Content\Articles;
use Edict\Tests\DbTestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * Publishing at a time ahead. The test clock starts at 2027-01-15 09:00 Berlin time.
 */
final class ScheduleFlowTest extends DbTestCase
{
    private const SESSION = 'edict_session';

    /** 2027-01-15 18:00 in Berlin, nine hours after the clock's start. */
    private const EVENING = '2027-01-15T18:00';
    private const EVENING_AT = 1_800_000_000 + 9 * 3600;

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

    /**
     * A saved draft, never published.
     *
     * @param array<string, mixed> $fields
     */
    private function draft(array $fields = [], string $kind = 'article'): int
    {
        $response = $this->adminPost('/admin/articles', ['kind' => $kind]);
        self::assertSame(303, $response->getStatusCode());
        $id = (int) substr($response->getHeaderLine('Location'), strlen('/admin/articles/'));
        $this->save($id, $fields);

        return $id;
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function save(int $id, array $fields = []): void
    {
        $version = $this->service(Articles::class)->find($id)?->draftVersion;
        $response = $this->adminPost("/admin/articles/$id/autosave", ['version' => $version, 'writer' => 'test-tab', 'draft' => $fields + [
            'title' => 'Coming Soon',
            'slug' => '',
            'lang' => 'en',
            'teaser' => 'A teaser',
            'body' => json_decode(Doc::text('Words about zeppelins.'), true),
            'tags' => 'Airships',
        ]]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
    }

    /**
     * @param string|null $at a datetime-local value, or null to publish now
     */
    private function publish(int $id, ?string $at = null): ResponseInterface
    {
        $version = $this->service(Articles::class)->find($id)?->draftVersion;

        return $this->adminPost("/admin/articles/$id/publish", ['version' => $version] + ($at === null ? [] : ['at' => $at]));
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function scheduled(array $fields = [], string $at = self::EVENING, string $kind = 'article'): int
    {
        $id = $this->draft($fields, $kind);
        $response = $this->publish($id, $at);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame(['redirect' => "/admin/articles/$id"], json_decode((string) $response->getBody(), true));

        return $id;
    }

    /**
     * @return array<string, string> what readers get from every public surface, by path
     */
    private function publicSurfaces(): array
    {
        $pages = [];
        foreach (['/', '/?lang=en', '/archive', '/search?q=zeppelins', '/feed.atom', '/feed.atom?lang=en', '/sitemap.xml'] as $path) {
            $response = $this->get($path);
            self::assertSame(200, $response->getStatusCode(), $path);
            $pages[$path] = (string) $response->getBody();
        }

        return $pages;
    }

    /**
     * @return array<string, string> what publishing refused, by field
     */
    private static function errors(ResponseInterface $response): array
    {
        $data = json_decode((string) $response->getBody(), true);
        self::assertIsArray($data);
        self::assertIsArray($data['errors'] ?? null);
        $errors = [];
        foreach ($data['errors'] as $field => $message) {
            $errors[(string) $field] = is_string($message) ? $message : '';
        }

        return $errors;
    }

    private function revisionCount(int $id): int
    {
        $stmt = $this->pdo->prepare('SELECT count(*) FROM article_revision WHERE article_id = ?');
        $stmt->execute([$id]);

        return (int) $stmt->fetchColumn();
    }

    private function mediaHash(int $mediaId): string
    {
        $stmt = $this->pdo->prepare('SELECT sha256 FROM media WHERE id = ?');
        $stmt->execute([$mediaId]);

        return (string) $stmt->fetchColumn();
    }

    private function imageRow(): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO media (sha256, kind, extension, bytes, original_name, width, height, status, created_at)
             VALUES (?, 'image', 'jpg', 1000, 'x.jpg', 1600, 900, 'ready', now()) RETURNING id",
        );
        $stmt->execute([hash('sha256', random_bytes(8))]);
        $id = (int) $stmt->fetchColumn();
        $insert = $this->pdo->prepare('INSERT INTO media_variant (media_id, preset, format, width, height, bytes) VALUES (?, ?, ?, ?, ?, 10)');
        $insert->execute([$id, 'w480-jpg', 'jpg', 480, 270]);
        $insert->execute([$id, 'full', 'jpg', 1600, 900]);

        return $id;
    }

    public function testAScheduledArticleIsHiddenEverywhereUntilItsTimeThenEverywhere(): void
    {
        // Something public, so the lists and feeds aren't simply empty.
        $this->scheduled(['title' => 'Already Out', 'tags' => 'Airships'], '2027-01-15T09:30');
        $this->clock->advance(3600);

        $cover = $this->imageRow();
        $id = $this->scheduled(['cover' => (string) $cover, 'tags' => 'Airships, Brand New Tag']);
        $hash = $this->mediaHash($cover);
        // A link from an old address, as if one existed: it must not reveal the piece either.
        $this->pdo->prepare('INSERT INTO slug_redirect (old_slug, article_id) VALUES (?, ?)')->execute(['old-address', $id]);

        $article = $this->service(Articles::class)->find($id);
        self::assertNotNull($article);
        self::assertSame('published', $article->status);
        self::assertSame(self::EVENING_AT, $article->publishedAt);
        self::assertTrue($article->scheduled);

        foreach (['/coming-soon', '/old-address', '/tag/brand-new-tag', '/tag/brand-new-tag/feed.atom'] as $path) {
            $response = $this->get($path);
            self::assertSame(404, $response->getStatusCode(), $path);
            self::assertSame('', $response->getHeaderLine('Location'), $path);
        }
        self::assertSame(200, $this->get('/tag/airships')->getStatusCode());
        foreach (['/tag/airships', '/tag/airships/feed.atom'] as $path) {
            self::assertStringNotContainsString('Coming Soon', (string) $this->get($path)->getBody(), $path);
        }
        foreach ($this->publicSurfaces() as $path => $body) {
            self::assertStringContainsString('already-out', $body, $path);
            self::assertStringNotContainsString('Coming Soon', $body, $path);
            self::assertStringNotContainsString('coming-soon', $body, $path);
            self::assertStringNotContainsString('brand-new-tag', $body, $path);
            self::assertStringNotContainsString($hash, $body, "$path: not even the cover's address");
        }
        self::assertStringContainsString('1 result', $this->publicSurfaces()['/search?q=zeppelins']);
        // Readers can't like or comment on what they can't see.
        self::assertSame(404, $this->post("/votes/$id")->getStatusCode());
        self::assertSame(404, $this->post("/comments/$id", ['body' => 'First!'])->getStatusCode());

        // A minute before: still nothing.
        $this->clock->advance(self::EVENING_AT - 60 - $this->clock->now());
        self::assertSame(404, $this->get('/coming-soon')->getStatusCode());

        // On the minute, with nothing else having happened: everywhere.
        $this->clock->advance(60);
        $page = $this->get('/coming-soon');
        self::assertSame(200, $page->getStatusCode());
        $html = (string) $page->getBody();
        self::assertStringContainsString('15 January 2027', $html);
        self::assertStringNotContainsString('Updated on', $html, 'going live is not a change');
        self::assertStringContainsString($hash, $html, 'with its cover');
        self::assertSame(301, $this->get('/old-address')->getStatusCode());
        self::assertSame('/coming-soon', $this->get('/old-address')->getHeaderLine('Location'));
        self::assertSame(200, $this->get('/tag/brand-new-tag')->getStatusCode());
        self::assertStringContainsString('Coming Soon', (string) $this->get('/tag/brand-new-tag/feed.atom')->getBody());

        $surfaces = $this->publicSurfaces();
        foreach (['/', '/?lang=en', '/archive', '/feed.atom', '/feed.atom?lang=en'] as $path) {
            self::assertStringContainsString('Coming Soon', $surfaces[$path], $path);
        }
        self::assertStringContainsString('2 results', $surfaces['/search?q=zeppelins']);
        self::assertStringContainsString('<loc>http://localhost:8080/coming-soon</loc>', $surfaces['/sitemap.xml']);
        self::assertStringContainsString('<loc>http://localhost:8080/tag/brand-new-tag</loc>', $surfaces['/sitemap.xml']);
        // The feed dates it from going live, not from when it was scheduled.
        $evening = gmdate(DATE_ATOM, self::EVENING_AT);
        self::assertStringContainsString("<published>$evening</published>", $surfaces['/feed.atom']);
        self::assertStringContainsString("<updated>$evening</updated>", $surfaces['/feed.atom']);
        self::assertLessThan(strpos($surfaces['/'], 'Already Out'), strpos($surfaces['/'], 'Coming Soon'), 'newest first, by its publish time');
    }

    public function testAScheduledPageIsHiddenUntilItsTimeToo(): void
    {
        $this->scheduled(['title' => 'Imprint', 'slug' => 'imprint-page'], kind: 'page');

        self::assertSame(404, $this->get('/imprint-page')->getStatusCode());
        self::assertStringNotContainsString('imprint-page', (string) $this->get('/sitemap.xml')->getBody());
        self::assertSame([], $this->service(Articles::class)->publishedPages(), 'not offered as the imprint yet');

        $this->clock->advance(self::EVENING_AT - $this->clock->now());
        self::assertSame(200, $this->get('/imprint-page')->getStatusCode());
        self::assertCount(1, $this->service(Articles::class)->publishedPages());
    }

    public function testSchedulingChecksTheTextAsPublishingDoes(): void
    {
        $id = $this->draft(['title' => '']);

        $response = $this->publish($id, self::EVENING);

        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey('title', self::errors($response));
        self::assertSame('draft', $this->service(Articles::class)->find($id)?->status);
    }

    public function testPastAndImpossibleTimesAreRefused(): void
    {
        $id = $this->draft(['title' => '']);
        $cases = [
            '2027-01-15T08:59' => 'has passed',
            '2027-01-15T09:00' => 'has passed',
            '2020-01-01T12:00' => 'has passed',
            '2027-02-30T12:00' => 'valid date and time',
            '2027-03-28T02:30' => 'valid date and time',
            'tomorrow' => 'valid date and time',
            '2040-01-01T12:00' => 'within the next ten years',
        ];
        foreach ($cases as $at => $message) {
            $response = $this->publish($id, $at);
            self::assertSame(422, $response->getStatusCode(), $at);
            $errors = self::errors($response);
            self::assertStringContainsString($message, $errors['at'] ?? '', $at);
            self::assertArrayHasKey('title', $errors, 'all problems at once');
        }
        self::assertSame(
            'That time (15 Jan 2027, 08:59) has passed. Choose a time in the future, or publish now.',
            self::errors($this->publish($id, '2027-01-15T08:59'))['at'] ?? null,
        );

        $article = $this->service(Articles::class)->find($id);
        self::assertSame('draft', $article?->status);
        self::assertNull($article->publishedAt);
        self::assertSame(0, $this->revisionCount($id));
    }

    public function testReschedulingMovesTheTime(): void
    {
        $id = $this->scheduled();
        self::assertSame(1, $this->revisionCount($id));

        self::assertSame(200, $this->publish($id, '2027-01-16T07:30')->getStatusCode());

        $article = $this->service(Articles::class)->find($id);
        self::assertSame('published', $article?->status);
        self::assertSame(self::EVENING_AT + 13 * 3600 + 1800, $article->publishedAt);
        self::assertSame(1, $this->revisionCount($id), 'the version scheduled before never reached readers');

        $this->clock->advance(self::EVENING_AT - $this->clock->now() + 3600);
        self::assertSame(404, $this->get('/coming-soon')->getStatusCode(), 'not at the old time');
        $this->clock->advance(12 * 3600 + 1800);
        self::assertSame(200, $this->get('/coming-soon')->getStatusCode(), 'at the new one');
    }

    public function testChangesToAScheduledPieceGoWithTheNextSchedule(): void
    {
        $id = $this->scheduled();
        $this->save($id, ['title' => 'Coming Later', 'slug' => 'later']);
        $editor = (string) $this->get("/admin/articles/$id", $this->admin)->getBody();
        self::assertStringContainsString('go live only once you schedule or publish again', $editor);

        self::assertSame(200, $this->publish($id, self::EVENING)->getStatusCode());

        self::assertSame('later', $this->service(Articles::class)->find($id)?->slug);
        $stmt = $this->pdo->query('SELECT count(*) FROM slug_redirect');
        self::assertNotFalse($stmt);
        self::assertSame(0, (int) $stmt->fetchColumn(), 'nobody ever saw the first address');
        $this->clock->advance(self::EVENING_AT - $this->clock->now());
        self::assertStringContainsString('Coming Later', (string) $this->get('/later')->getBody());
        self::assertSame(404, $this->get('/coming-soon')->getStatusCode());
    }

    public function testCancellingTurnsItBackIntoADraft(): void
    {
        $id = $this->scheduled();

        $response = $this->adminPost("/admin/articles/$id/unpublish");

        self::assertSame("/admin/articles/$id", $response->getHeaderLine('Location'));
        $article = $this->service(Articles::class)->find($id);
        self::assertSame('draft', $article?->status);
        self::assertNull($article->publishedAt);
        self::assertFalse($article->scheduled);
        self::assertSame('Coming Soon', $article->input()->title, 'the text stays');
        self::assertSame(0, $this->revisionCount($id), 'it never was a published version');

        $this->clock->advance(self::EVENING_AT - $this->clock->now() + 60);
        self::assertSame(404, $this->get('/coming-soon')->getStatusCode());

        // And it can be scheduled again, or published.
        self::assertSame(200, $this->publish($id, '2027-01-16T12:00')->getStatusCode());
        self::assertTrue($this->service(Articles::class)->find($id)?->scheduled);
    }

    public function testTheListCancelsAScheduleInOneClick(): void
    {
        $id = $this->scheduled();
        $page = $this->scheduled(['title' => 'Later Page', 'slug' => 'later-page'], kind: 'page');

        $response = $this->adminPost("/admin/articles/$id/unpublish", ['back' => 'list']);

        self::assertSame('/admin/articles?done=unscheduled', $response->getHeaderLine('Location'));
        self::assertSame('draft', $this->service(Articles::class)->find($id)?->status);
        self::assertStringContainsString('Schedule cancelled. It is a draft again', (string) $this->get('/admin/articles?done=unscheduled', $this->admin)->getBody());
        self::assertSame('/admin/articles?kind=page&done=unscheduled', $this->adminPost("/admin/articles/$page/unpublish", ['back' => 'list'])->getHeaderLine('Location'));
    }

    public function testPublishNowOnAScheduledPiece(): void
    {
        $id = $this->scheduled();

        self::assertSame(200, $this->publish($id)->getStatusCode());

        self::assertSame(200, $this->get('/coming-soon')->getStatusCode());
        self::assertSame($this->clock->now(), $this->service(Articles::class)->find($id)?->publishedAt);
        self::assertSame(1, $this->revisionCount($id));
    }

    public function testOnlyPiecesReadersNeverSawCanBeScheduled(): void
    {
        $live = $this->draft();
        self::assertSame(200, $this->publish($live)->getStatusCode());
        $first = $this->service(Articles::class)->find($live)?->publishedAt;

        $response = $this->publish($live, self::EVENING);
        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('can only be published now', (string) $response->getBody());
        self::assertSame($first, $this->service(Articles::class)->find($live)?->publishedAt);
        self::assertSame(200, $this->get('/coming-soon')->getStatusCode());
        self::assertStringNotContainsString('id="schedule-toggle"', (string) $this->get("/admin/articles/$live", $this->admin)->getBody());

        // Unpublished after being public: it keeps its date, so it can't move into the future either.
        $this->adminPost("/admin/articles/$live/unpublish");
        self::assertSame(422, $this->publish($live, self::EVENING)->getStatusCode());
        self::assertSame('unpublished', $this->service(Articles::class)->find($live)?->status);
        self::assertStringNotContainsString('id="schedule-toggle"', (string) $this->get("/admin/articles/$live", $this->admin)->getBody());

        // A scheduled piece that went live is an ordinary live piece: unpublishing doesn't make it a draft.
        $went = $this->scheduled(['title' => 'Went Live']);
        $this->clock->advance(self::EVENING_AT - $this->clock->now());
        self::assertSame(422, $this->publish($went, '2027-01-20T12:00')->getStatusCode());
        $this->adminPost("/admin/articles/$went/unpublish");
        $article = $this->service(Articles::class)->find($went);
        self::assertSame('unpublished', $article?->status);
        self::assertSame(self::EVENING_AT, $article->publishedAt);
    }

    public function testTheEditorShowsTheSchedule(): void
    {
        $draft = $this->draft();
        $editor = (string) $this->get("/admin/articles/$draft", $this->admin)->getBody();
        self::assertStringContainsString('id="schedule-toggle"', $editor);
        self::assertStringContainsString('<input type="datetime-local" id="publish-at" value="" min="2027-01-15T09:00"', $editor);
        self::assertStringContainsString('data-now-label="Publish">Publish</button>', $editor);
        self::assertStringNotContainsString('Scheduled for', $editor);

        $id = $this->scheduled(['title' => 'Second']);
        $editor = (string) $this->get("/admin/articles/$id", $this->admin)->getBody();
        self::assertStringContainsString('Scheduled for <strong><time datetime="2027-01-15T18:00:00+01:00">15 Jan 2027, 18:00</time></strong>', $editor);
        self::assertStringContainsString('id="publish-at" value="2027-01-15T18:00"', $editor);
        self::assertStringContainsString('data-now-label="Publish">Schedule</button>', $editor);
        self::assertStringContainsString('id="publish-now"', $editor);
        self::assertStringContainsString('>Cancel schedule</button>', $editor);
        self::assertStringNotContainsString('>View</a>', $editor, 'readers would get a 404 there');
        self::assertStringContainsString('(what readers will see)', $editor);

        $preview = (string) $this->get("/admin/articles/$id/preview", $this->admin)->getBody();
        self::assertStringContainsString('Status: scheduled for 15 Jan 2027, 18:00 (Berlin time).', $preview);

        $this->clock->advance(self::EVENING_AT - $this->clock->now());
        $editor = (string) $this->get("/admin/articles/$id", $this->admin)->getBody();
        self::assertStringNotContainsString('Scheduled for', $editor);
        self::assertStringContainsString('data-now-label="Update">Update</button>', $editor);
        self::assertStringContainsString('>View</a>', $editor);
    }

    public function testTheListAndTheDashboardShowWhatIsScheduled(): void
    {
        $article = $this->scheduled(['title' => 'Evening Piece']);
        $page = $this->scheduled(['title' => 'Morning Page', 'slug' => 'morning-page'], '2027-01-15T10:00', 'page');

        $list = (string) $this->get('/admin/articles', $this->admin)->getBody();
        self::assertStringContainsString('<span class="pill pill-scheduled">scheduled</span> <time class="when" datetime="2027-01-15T18:00:00+01:00">15 Jan 2027, 18:00</time>', $list);
        self::assertStringContainsString('action="/admin/articles/' . $article . '/unpublish"', $list);
        self::assertStringContainsString('Cancel schedule<span class="visually-hidden"> Evening Piece</span>', $list);
        self::assertStringContainsString('15 Jan 2027, 10:00', (string) $this->get('/admin/articles?kind=page', $this->admin)->getBody());

        $dashboard = (string) $this->get('/admin', $this->admin)->getBody();
        self::assertStringContainsString('id="scheduled-heading">Scheduled</h2>', $dashboard);
        $morning = strpos($dashboard, '<a href="/admin/articles/' . $page . '">Morning Page</a>');
        $evening = strpos($dashboard, '<a href="/admin/articles/' . $article . '">Evening Piece</a>');
        self::assertNotFalse($morning);
        self::assertNotFalse($evening);
        self::assertLessThan($evening, $morning, 'soonest first');
        self::assertStringContainsString('15 Jan 2027, 18:00</time>', $dashboard);
        self::assertStringContainsString('Nothing is unfinished', $dashboard, 'scheduled is finished');

        $this->clock->advance(3600);
        $dashboard = (string) $this->get('/admin', $this->admin)->getBody();
        self::assertStringNotContainsString('Morning Page', $dashboard, 'live now');
        self::assertStringContainsString('Evening Piece', $dashboard);

        $this->clock->advance(self::EVENING_AT - $this->clock->now());
        self::assertStringNotContainsString('scheduled-heading', (string) $this->get('/admin', $this->admin)->getBody());
        $list = (string) $this->get('/admin/articles', $this->admin)->getBody();
        self::assertStringContainsString('<span class="pill pill-published">published</span>', $list);
        self::assertStringNotContainsString('pill-scheduled', $list);
    }
}
