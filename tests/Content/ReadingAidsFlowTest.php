<?php

declare(strict_types=1);

namespace Edict\Tests\Content;

use Edict\Auth\AdminAccount;
use Edict\Auth\SessionStore;
use Edict\Backup\Restore;
use Edict\Content\Article;
use Edict\Content\Articles;
use Edict\Tests\DbTestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * Previous and next, related articles and reading time (#88). The test clock starts
 * at 2027-01-15 09:00 Berlin time.
 */
final class ReadingAidsFlowTest extends DbTestCase
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
        $token = $sessions->create((int) $id->fetchColumn(), 365 * 86400);
        $session = $sessions->load($token);
        self::assertNotNull($session);
        $this->admin = [self::SESSION => $token];
        $this->csrf = $session->csrfToken;
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

    /**
     * @param array<string, mixed> $fields
     */
    private function draft(string $title, array $fields = [], string $kind = 'article'): int
    {
        $response = $this->adminPost('/admin/articles', ['kind' => $kind]);
        self::assertSame(303, $response->getStatusCode(), (string) $response->getBody());
        $id = (int) substr($response->getHeaderLine('Location'), strlen('/admin/articles/'));
        $this->save($id, ['title' => $title] + $fields);

        return $id;
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function save(int $id, array $fields): void
    {
        $version = $this->service(Articles::class)->find($id)?->draftVersion;
        $draft = $fields + ['title' => 'Untitled', 'slug' => '', 'lang' => 'en', 'teaser' => '', 'body' => json_decode(Doc::text('Some words'), true), 'tags' => ''];
        $response = $this->adminPost("/admin/articles/$id/autosave", ['version' => $version, 'writer' => 'test-tab', 'draft' => $draft]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
    }

    /**
     * @param string|null $at a datetime-local value in Berlin time, or null for now
     */
    private function publish(int $id, ?string $at = null): void
    {
        $version = $this->service(Articles::class)->find($id)?->draftVersion;
        $response = $this->adminPost("/admin/articles/$id/publish", ['version' => $version] + ($at === null ? [] : ['at' => $at]));
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
    }

    /**
     * Published, then the clock moves on an hour, so the next one is newer.
     *
     * @param array<string, mixed> $fields
     */
    private function published(string $title, array $fields = [], string $kind = 'article'): int
    {
        $id = $this->draft($title, $fields, $kind);
        $this->publish($id);
        $this->clock->advance(3600);

        return $id;
    }

    private function article(int $id): Article
    {
        $article = $this->service(Articles::class)->find($id);
        self::assertNotNull($article);

        return $article;
    }

    /**
     * @return array{previous: int|null, next: int|null}
     */
    private function neighbourIds(int $id): array
    {
        $found = $this->service(Articles::class)->neighbours($this->article($id));

        return ['previous' => $found['previous']?->id, 'next' => $found['next']?->id];
    }

    /**
     * @return list<int>
     */
    private function relatedIds(int $id): array
    {
        return array_map(static fn(Article $a): int => $a->id, $this->service(Articles::class)->related($this->article($id), 3));
    }

    /**
     * @return array<string, mixed> a body field of this many words
     */
    private static function words(int $count): array
    {
        $body = json_decode(Doc::text(trim(str_repeat('word ', $count))), true);
        self::assertIsArray($body);

        return $body;
    }

    private function page(string $path): string
    {
        $response = $this->get($path);
        self::assertSame(200, $response->getStatusCode(), $path);

        return (string) $response->getBody();
    }

    // --- Previous and next -----------------------------------------------------

    public function testPreviousAndNextFollowThePublishDateAcrossLanguages(): void
    {
        $first = $this->published('First one');
        $second = $this->published('Zweiter Beitrag', ['lang' => 'de']);
        $this->published('A static page', [], 'page');
        $third = $this->published('Third one');

        self::assertSame(['previous' => null, 'next' => $second], $this->neighbourIds($first), 'the oldest has no previous');
        self::assertSame(['previous' => $first, 'next' => $third], $this->neighbourIds($second), 'across languages, and pages are skipped');
        self::assertSame(['previous' => $second, 'next' => null], $this->neighbourIds($third), 'the newest has no next');

        $html = $this->page('/zweiter-beitrag');
        self::assertStringContainsString('<nav class="article-nav" aria-label="More articles">', $html);
        self::assertMatchesRegularExpression('~<a href="/first-one" rel="prev">.*?First one</span>~s', $html);
        self::assertMatchesRegularExpression('~<a href="/third-one" rel="next">.*?Third one</span>~s', $html);
        self::assertStringContainsString('<span class="article-nav-title" lang="en">Third one</span>', $html);

        self::assertStringNotContainsString('rel="next"', $this->page('/third-one'));
        self::assertStringNotContainsString('article-nav', $this->page('/a-static-page'), 'pages have none');
    }

    public function testOnlyPublicArticlesAreNeighbours(): void
    {
        $articles = $this->service(Articles::class);
        $first = $this->published('First one');
        $unpublished = $this->published('Taken back');
        $trashed = $this->published('Thrown away');
        $this->draft('Never published');
        $middle = $this->published('Middle one');
        $scheduled = $this->draft('Coming later');
        $this->publish($scheduled, '2027-02-01T09:00');

        self::assertNotNull($articles->unpublish($unpublished));
        $articles->trash($trashed);

        self::assertSame(['previous' => $first, 'next' => null], $this->neighbourIds($middle), 'nothing scheduled, unpublished, trashed or drafted');
        self::assertSame(['previous' => null, 'next' => $middle], $this->neighbourIds($first));
        self::assertStringNotContainsString('coming-later', $this->page('/middle-one'));

        // Its time comes: now it is the next one.
        $this->clock->advance(30 * 86400);
        self::assertSame(['previous' => $first, 'next' => $scheduled], $this->neighbourIds($middle));
    }

    public function testTheSameSecondOrdersById(): void
    {
        $a = $this->draft('Same second A');
        $b = $this->draft('Same second B');
        $this->publish($a);
        $this->publish($b);

        self::assertSame(['previous' => null, 'next' => $b], $this->neighbourIds($a));
        self::assertSame(['previous' => $a, 'next' => null], $this->neighbourIds($b));
    }

    public function testAnArticleWithoutNeighboursShowsNoNavigation(): void
    {
        $this->published('All alone');

        self::assertStringNotContainsString('article-nav', $this->page('/all-alone'));
    }

    // --- Related ---------------------------------------------------------------

    public function testRelatedArticlesShareTheMostTagsNewestFirstOnTies(): void
    {
        $articles = $this->service(Articles::class);
        $oldestOne = $this->published('Oldest single', ['tags' => 'Beta']);
        $olderOne = $this->published('Older single', ['tags' => 'Gamma']);
        $two = $this->published('Shares two', ['tags' => 'Alpha, Beta']);
        $newerOne = $this->published('Newer single', ['tags' => 'Alpha', 'lang' => 'de']);
        $this->published('Nothing shared', ['tags' => 'Zeta']);
        $this->published('Untagged');
        $unpublished = $this->published('All three, taken back', ['tags' => 'Alpha, Beta, Gamma']);
        $trashed = $this->published('All three, trashed', ['tags' => 'Alpha, Beta, Gamma']);
        $scheduled = $this->draft('All three, later', ['tags' => 'Alpha, Beta, Gamma']);
        $this->publish($scheduled, '2027-02-01T09:00');
        $this->published('Page with tags', ['tags' => 'Alpha, Beta, Gamma'], 'page');
        self::assertNotNull($articles->unpublish($unpublished));
        $articles->trash($trashed);

        $self = $this->published('The one read', ['tags' => 'Alpha, Beta, Gamma']);

        // Two shared first; then one each, newest first; the oldest of those falls off at three.
        self::assertSame([$two, $newerOne, $olderOne], $this->relatedIds($self));
        self::assertNotContains($oldestOne, $this->relatedIds($self));

        $html = $this->page('/the-one-read');
        self::assertStringContainsString('<section class="related" aria-labelledby="related-heading">', $html);
        self::assertStringContainsString('<a class="related-title" href="/shares-two">Shares two</a>', $html);
        self::assertStringNotContainsString('href="/nothing-shared"', $html);
        // Under the article, above the comments.
        self::assertLessThan(strpos($html, 'id="comments"') ?: PHP_INT_MAX, strpos($html, 'class="related"'));
        self::assertGreaterThan(strpos($html, '</article>'), strpos($html, 'class="related"'));
    }

    public function testNoSharedTagMeansNoRelatedArticles(): void
    {
        $this->published('Other topic', ['tags' => 'Zeta']);
        $this->published('Untagged too');
        $alone = $this->published('Own topic', ['tags' => 'Alpha']);
        $untagged = $this->published('No tags at all');

        self::assertSame([], $this->relatedIds($alone));
        self::assertSame([], $this->relatedIds($untagged));
        self::assertStringNotContainsString('class="related"', $this->page('/own-topic'));
    }

    public function testARelatedArticleShowsItsCoverThumbnail(): void
    {
        $stmt = $this->pdo->query(
            "INSERT INTO media (sha256, kind, extension, bytes, original_name, width, height, status, created_at)
             VALUES ('" . hash('sha256', 'cover') . "', 'image', 'jpg', 1000, 'x.jpg', 1600, 900, 'ready', now()) RETURNING id",
        );
        self::assertNotFalse($stmt);
        $cover = (int) $stmt->fetchColumn();
        $insert = $this->pdo->prepare('INSERT INTO media_variant (media_id, preset, format, width, height, bytes) VALUES (?, ?, ?, ?, ?, 10)');
        $insert->execute([$cover, 'w480-jpg', 'jpg', 480, 270]);
        $insert->execute([$cover, 'full', 'jpg', 1600, 900]);

        $this->published('With a cover', ['tags' => 'Alpha', 'cover' => (string) $cover, 'coverAlt' => 'A view']);
        $this->published('Reading this', ['tags' => 'Alpha']);

        $html = $this->page('/reading-this');
        $related = substr($html, (int) strpos($html, 'class="related"'));
        self::assertStringContainsString('<div class="article-cover-thumb">', $related);
        self::assertStringContainsString('alt="" loading="lazy"', $related, 'beside the title it is decoration');
    }

    // --- Reading time ----------------------------------------------------------

    public function testReadingTimeIsStoredOnPublishAndKeptUntilTheNextOne(): void
    {
        $id = $this->draft('Long read', ['body' => self::words(221)]);
        $this->publish($id);
        self::assertSame(2, $this->article($id)->readingMinutes, '221 words at 220 a minute, rounded up');
        self::assertStringContainsString('<span class="reading-time">2 min read</span>', $this->page('/long-read'));
        self::assertStringContainsString('<span class="reading-time">2 min read</span>', $this->page('/'), 'in the summary too');

        // A draft doesn't change what readers see, but the preview shows the draft's.
        $this->save($id, ['title' => 'Long read', 'body' => self::words(2200)]);
        self::assertSame(2, $this->article($id)->readingMinutes);
        $preview = (string) $this->get("/admin/articles/$id/preview", $this->admin)->getBody();
        self::assertStringContainsString('<span class="reading-time">10 min read</span>', $preview);

        // Republished: the new text counts.
        $this->publish($id);
        self::assertSame(10, $this->article($id)->readingMinutes);

        $this->save($id, ['title' => 'Long read', 'body' => self::words(5)]);
        $this->publish($id);
        self::assertSame(1, $this->article($id)->readingMinutes, 'at least a minute');
    }

    public function testReadingTimeCountsTheBodyOnly(): void
    {
        $title = trim(str_repeat('title ', 33));
        $id = $this->draft($title, ['teaser' => trim(str_repeat('teaser ', 120)), 'body' => self::words(220)]);
        $this->publish($id);

        self::assertSame(1, $this->article($id)->readingMinutes);
    }

    public function testReadingTimeInGerman(): void
    {
        $this->published('Lange Lektüre', ['lang' => 'de', 'body' => self::words(1000)]);

        self::assertStringContainsString('<span class="reading-time">5 Min. Lesezeit</span>', $this->page('/lange-lektuere'));
    }

    public function testAScheduledPieceHasItsReadingTimeWhenItGoesLive(): void
    {
        $id = $this->draft('Coming soon', ['body' => self::words(441)]);
        $this->publish($id, '2027-01-15T18:00');
        self::assertSame(3, $this->article($id)->readingMinutes);

        $this->clock->advance(10 * 3600);
        self::assertStringContainsString('<span class="reading-time">3 min read</span>', $this->page('/coming-soon'));
    }

    public function testARestoreGetsTheReadingTimeFromTheRestoredText(): void
    {
        $id = $this->published('Kept safe', ['body' => self::words(700), 'tags' => 'Alpha']);
        $response = $this->get('/admin/export', $this->admin);
        self::assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        self::assertIsArray($data);

        $this->pdo->exec('TRUNCATE article, tag, media, upload, job, setting RESTART IDENTITY CASCADE');
        $this->service(Restore::class)->fromJson(json_encode($data, JSON_THROW_ON_ERROR));

        self::assertSame(4, $this->article($id)->readingMinutes);
        self::assertStringContainsString('<span class="reading-time">4 min read</span>', $this->page('/kept-safe'));
    }

    /**
     * The migration's ADD COLUMN fills in the rows already there. Run again here on
     * rows written without the column, as an install upgrading to it has them.
     */
    public function testTheMigrationBackfillsExistingArticles(): void
    {
        $long = $this->published('Before the column', ['body' => self::words(900)]);
        $short = $this->published('Short and old', ['body' => self::words(3)]);
        $draft = $this->draft('Draft only', ['body' => self::words(5000)]);

        $this->pdo->exec('ALTER TABLE article DROP COLUMN reading_minutes; DROP FUNCTION edict_reading_minutes(text)');
        $migration = file_get_contents(dirname(__DIR__, 2) . '/migrations/0018_reading_time.sql');
        self::assertIsString($migration);
        $this->pdo->exec($migration);

        self::assertSame(5, $this->article($long)->readingMinutes);
        self::assertSame(1, $this->article($short)->readingMinutes);
        self::assertSame(1, $this->article($draft)->readingMinutes, 'a draft has no published text yet');
    }
}
