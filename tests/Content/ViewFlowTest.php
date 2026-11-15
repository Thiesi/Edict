<?php

declare(strict_types=1);

namespace Edict\Tests\Content;

use Edict\Auth\AdminAccount;
use Edict\Auth\SessionStore;
use Edict\Content\Articles;
use Edict\Content\Views;
use Edict\Tests\DbTestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;

final class ViewFlowTest extends DbTestCase
{
    private const SESSION = 'edict_session';
    private const BROWSER = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:132.0) Gecko/20100101 Firefox/132.0';
    private const READER = '198.51.100.1';

    /** @var array<string, string> */
    private array $admin;
    private string $csrf;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service(AdminAccount::class)->create('sovereign', 'correct horse battery');
        $id = $this->pdo->query('SELECT id FROM app_user WHERE is_admin');
        self::assertNotFalse($id);
        [$this->admin, $this->csrf] = $this->signIn((int) $id->fetchColumn());
    }

    /**
     * @return array{array<string, string>, string}
     */
    private function signIn(int $userId): array
    {
        $sessions = $this->service(SessionStore::class);
        // Long enough to outlive the clock jumps in these tests.
        $token = $sessions->create($userId, 365 * 86400);
        $session = $sessions->load($token);
        self::assertNotNull($session);

        return [[self::SESSION => $token], $session->csrfToken];
    }

    /**
     * @param array<string, string> $fields
     */
    private function adminPost(string $path, array $fields = []): ResponseInterface
    {
        return $this->post($path, $fields + ['_csrf' => $this->csrf], $this->admin);
    }

    private function saved(string $title, string $kind = 'article'): int
    {
        $response = $this->adminPost('/admin/articles', ['kind' => $kind]);
        self::assertSame(303, $response->getStatusCode());
        $id = (int) substr($response->getHeaderLine('Location'), strlen('/admin/articles/'));
        $version = $this->service(Articles::class)->find($id)?->draftVersion;
        $saved = $this->post("/admin/articles/$id/autosave", [
            '_csrf' => $this->csrf,
            'version' => $version,
            'writer' => 'test-tab',
            'draft' => ['title' => $title, 'slug' => '', 'lang' => 'en', 'teaser' => 'A teaser', 'body' => json_decode(Doc::text('Some text'), true), 'tags' => ''],
        ], $this->admin);
        self::assertSame(200, $saved->getStatusCode(), (string) $saved->getBody());

        return $id;
    }

    private function published(string $title, string $kind = 'article'): int
    {
        $id = $this->saved($title, $kind);
        $version = $this->service(Articles::class)->find($id)?->draftVersion;
        self::assertSame(200, $this->adminPost("/admin/articles/$id/publish", ['version' => (string) $version])->getStatusCode());

        return $id;
    }

    /**
     * A request as a browser sends it, from a reader's own address.
     *
     * @param array<string, string> $cookies
     */
    private function visit(string $path, string $ip = self::READER, string $agent = self::BROWSER, string $method = 'GET', array $cookies = []): ResponseInterface
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest($method, self::ORIGIN . $path, ['REMOTE_ADDR' => $ip])
            ->withCookieParams($cookies);
        if ($agent !== '') {
            $request = $request->withHeader('User-Agent', $agent);
        }

        return $this->app->handle($request);
    }

    private function views(int $articleId): int
    {
        $stmt = $this->pdo->prepare('SELECT coalesce(sum(views), 0) FROM article_view_day WHERE article_id = ?');
        $stmt->execute([$articleId]);

        return (int) $stmt->fetchColumn();
    }

    private function rows(string $table): int
    {
        $stmt = $this->pdo->query("SELECT count(*) FROM $table");
        self::assertNotFalse($stmt);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @return list<string>
     */
    private function days(string $table): array
    {
        $stmt = $this->pdo->query("SELECT DISTINCT to_char(day, 'YYYY-MM-DD') FROM $table ORDER BY 1");
        self::assertNotFalse($stmt);

        /** @var list<string> */
        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }

    public function testAReaderCountsOncePerArticleAndDay(): void
    {
        $id = $this->published('Counted');
        $other = $this->published('Also counted');

        for ($i = 0; $i < 3; ++$i) {
            self::assertSame(200, $this->visit('/counted')->getStatusCode());
        }
        self::assertSame(1, $this->views($id), 'reloading is not reading again');

        $this->clock->advance(3600);
        $this->visit('/counted');
        self::assertSame(1, $this->views($id), 'nor later the same day');

        $this->visit('/counted', '198.51.100.2');
        $this->visit('/counted', self::READER, 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_1 like Mac OS X) AppleWebKit/605.1.15 Version/18.1 Mobile/15E148 Safari/604.1');
        self::assertSame(3, $this->views($id), 'another address or another browser is another reader');

        $this->visit('/also-counted');
        self::assertSame(1, $this->views($other), 'each article counts on its own');

        // 1_800_000_000 is 15 January 2027, 09:00 in Berlin.
        self::assertSame(['2027-01-15'], $this->days('article_view_day'));
        self::assertSame(1, $this->rows('view_salt'), 'one salt for the day, for all articles');
    }

    public function testTheNextDayCountsAgain(): void
    {
        $id = $this->published('Counted');
        $this->visit('/counted');

        // To 23:59 in Berlin (22:59 UTC): still the same day.
        $this->clock->advance(14 * 3600 + 59 * 60);
        $this->visit('/counted');
        self::assertSame(1, $this->views($id));

        // A minute later it is the next day there, though not yet in UTC.
        $this->clock->advance(60);
        $this->visit('/counted');
        self::assertSame(2, $this->views($id));
        self::assertSame(['2027-01-15', '2027-01-16'], $this->days('article_view_day'));

        $this->visit('/counted');
        self::assertSame(2, $this->views($id), 'and once again that day');
    }

    public function testTheAdminBotsAndHeadRequestsDoNotCount(): void
    {
        $id = $this->published('Counted');

        self::assertSame(200, $this->visit('/counted', cookies: $this->admin)->getStatusCode());
        self::assertSame(0, $this->views($id), 'the admin reading their own article');

        $bots = [
            'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
            'Mozilla/5.0 (compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm)',
            'facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)',
            'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; ClaudeBot/1.0; +claudebot@anthropic.com)',
            'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/131.0.0.0 Safari/537.36',
            'curl/8.10.1',
            'python-requests/2.32.3',
            'Feedly/1.0 (+http://www.feedly.com/fetcher.html)',
            'Mastodon/4.3.1 (http.rb/5.2.0; +https://social.example/)',
        ];
        foreach ($bots as $i => $bot) {
            self::assertSame(200, $this->visit('/counted', '198.51.100.' . (10 + $i), $bot)->getStatusCode());
        }
        $this->visit('/counted', '198.51.100.50', '');
        self::assertSame(0, $this->views($id), 'bots, and anything without a user agent');

        $this->visit('/counted', '198.51.100.51', self::BROWSER, 'HEAD');
        self::assertSame(0, $this->views($id), 'a HEAD request reads nothing');

        $this->visit('/counted');
        self::assertSame(1, $this->views($id), 'a reader in a browser does count');

        $insert = $this->pdo->query("INSERT INTO app_user (display_name) VALUES ('reader') RETURNING id");
        self::assertNotFalse($insert);
        [$reader] = $this->signIn((int) $insert->fetchColumn());
        $this->visit('/counted', '198.51.100.52', self::BROWSER, 'GET', $reader);
        self::assertSame(2, $this->views($id), 'so does a signed-in reader');
    }

    public function testPreviewsDraftsAndOtherPagesDoNotCount(): void
    {
        $draft = $this->saved('Not yet');
        $id = $this->published('Counted');

        self::assertSame(404, $this->visit('/not-yet')->getStatusCode());
        self::assertSame(200, $this->visit("/admin/articles/$draft/preview", cookies: $this->admin)->getStatusCode());
        self::assertSame(200, $this->visit("/admin/articles/$id/preview", cookies: $this->admin)->getStatusCode());
        self::assertSame(200, $this->visit('/counted?theme=quiet', cookies: $this->admin)->getStatusCode());
        foreach (['/', '/archive', '/search?q=counted', '/feed.atom', '/no-such-article'] as $path) {
            $this->visit($path);
        }

        self::assertSame(0, $this->rows('article_view_day'));
        self::assertSame(0, $this->rows('view_seen'));
        self::assertSame(0, $this->rows('view_salt'), 'no salt either, until something counts');

        $page = $this->published('About', 'page');
        $this->visit('/about');
        self::assertSame(1, $this->views($page), 'pages are read too');
    }

    public function testThePastDaysSaltAndHashesAreForgotten(): void
    {
        $id = $this->published('Counted');
        $this->visit('/counted');
        $this->visit('/counted', '198.51.100.2');
        self::assertSame(1, $this->rows('view_salt'));
        self::assertSame(2, $this->rows('view_seen'));

        // The worker clears them once the day is over, even if nobody reads anything.
        $this->clock->advance(86400);
        $this->service(Views::class)->forgetPastDays();
        self::assertSame(0, $this->rows('view_salt'));
        self::assertSame(0, $this->rows('view_seen'));
        self::assertSame(2, $this->views($id), 'the counts stay');

        // Or the first view of a new day does.
        $this->visit('/counted');
        $this->clock->advance(86400);
        $this->visit('/counted', '198.51.100.3');
        self::assertSame(['2027-01-17'], $this->days('view_salt'));
        self::assertSame(['2027-01-17'], $this->days('view_seen'));
        self::assertSame(1, $this->rows('view_seen'));
        self::assertSame(4, $this->views($id));
    }

    public function testNothingAboutTheReaderIsStored(): void
    {
        $this->published('Counted');
        $ip = '198.51.100.77';
        $agent = 'Mozilla/5.0 (X11; Linux x86_64) Distinctive/1234 Firefox/132.0';
        $this->visit('/counted', $ip, $agent);
        self::assertSame(1, $this->rows('view_seen'));

        $tables = $this->pdo->query("SELECT tablename FROM pg_tables WHERE schemaname = 'public'");
        self::assertNotFalse($tables);
        foreach ($tables->fetchAll(\PDO::FETCH_COLUMN) as $table) {
            self::assertIsString($table);
            $rows = $this->pdo->query("SELECT t::text FROM \"$table\" t");
            self::assertNotFalse($rows);
            foreach ($rows->fetchAll(\PDO::FETCH_COLUMN) as $row) {
                self::assertIsString($row);
                foreach ([$ip, 'Distinctive', bin2hex($ip), bin2hex('Distinctive')] as $needle) {
                    self::assertStringNotContainsString($needle, $row, "found in $table");
                }
            }
        }

        $columns = $this->pdo->query(
            "SELECT table_name || '.' || column_name FROM information_schema.columns
             WHERE table_name IN ('article_view_day', 'view_salt', 'view_seen') ORDER BY 1",
        );
        self::assertNotFalse($columns);
        self::assertSame(
            ['article_view_day.article_id', 'article_view_day.day', 'article_view_day.views', 'view_salt.day', 'view_salt.salt', 'view_seen.day', 'view_seen.hash'],
            $columns->fetchAll(\PDO::FETCH_COLUMN),
            'a hash and a day per reader, nothing else',
        );

        $response = $this->visit('/counted', $ip, $agent);
        self::assertSame('', $response->getHeaderLine('Set-Cookie'), 'and no cookie');
    }

    public function testTheListEditorAndDashboardShowTheCounts(): void
    {
        $dashboard = (string) $this->get('/admin', $this->admin)->getBody();
        self::assertStringContainsString('No views in the last 30 days.', $dashboard);
        self::assertStringNotContainsString('<svg class="views-chart"', $dashboard);

        $id = $this->published('Counted');
        $page = $this->published('About', 'page');
        $this->visit('/counted');
        $this->visit('/counted', '198.51.100.2');
        $this->clock->advance(40 * 86400);
        $this->visit('/counted');
        $this->visit('/about');

        $list = (string) $this->get('/admin/articles', $this->admin)->getBody();
        self::assertStringContainsString('<th role="columnheader" class="num">Likes</th><th role="columnheader" class="num">Views</th>', $list);
        self::assertStringContainsString('<td role="cell" class="num">3<span class="cell-unit"> views</span><span class="visually-hidden">, </span> <small class="views-recent">1 in 30 days</small></td>', $list);
        $pages = (string) $this->get('/admin/articles?kind=page', $this->admin)->getBody();
        self::assertStringContainsString('<td role="cell" class="num">1<span class="cell-unit"> view</span>', $pages);

        $editor = (string) $this->get("/admin/articles/$id", $this->admin)->getBody();
        self::assertStringContainsString('Views: <strong>3</strong>, 1 in the last 30 days.', $editor);
        self::assertStringNotContainsString('Views: <strong>', (string) $this->get('/admin/articles/' . $this->saved('Draft'), $this->admin)->getBody(), 'nothing to count before publishing');

        $dashboard = (string) $this->get('/admin', $this->admin)->getBody();
        self::assertStringContainsString('<p class="big-number">2 <small>in the last 30 days</small></p>', $dashboard, 'the old views are outside the window');
        self::assertStringContainsString('<svg class="views-chart" viewBox="0 0 300 72" role="img" aria-labelledby="views-chart-label">', $dashboard);
        self::assertStringContainsString('<title id="views-chart-label">Views per day from 26 Jan to 24 Feb, at most 2 on one day</title>', $dashboard);
        self::assertStringContainsString('<title>24 Feb: 2 views</title>', $dashboard);
        self::assertStringContainsString('<title>23 Feb: 0 views</title>', $dashboard);
        self::assertSame(1, substr_count($dashboard, 'class="views-chart-bar"'), 'one day with views');
        self::assertSame(30, substr_count($dashboard, 'class="views-chart-hit"'));
        self::assertStringContainsString('<tr><td><time datetime="2027-02-24">24 Feb</time></td><td class="num">2</td></tr>', $dashboard);
        self::assertStringNotContainsString('style=', $dashboard, 'nothing inline, for the CSP');
        self::assertSame(1, $this->views($page));
    }
}
