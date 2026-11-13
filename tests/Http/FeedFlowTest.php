<?php

declare(strict_types=1);

namespace Edict\Tests\Http;

use Edict\Http\ErrorPage;
use Edict\Services;
use Edict\Tests\Content\Doc;
use Edict\Tests\DbTestCase;
use Slim\Exception\HttpNotFoundException;
use Slim\Psr7\Factory\ServerRequestFactory;

final class FeedFlowTest extends DbTestCase
{
    private function article(string $slug, string $lang, string $title, string $text, string $tag = '', string $kind = 'article', string $status = 'published'): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO article (kind, slug, lang, title, teaser, body_json, status, published_at, created_at, updated_at)
             VALUES (?, ?, ?, ?, 'A teaser & more', ?, ?, to_timestamp(?), now(), now()) RETURNING id",
        );
        $stmt->execute([$kind, $slug, $lang, $title, Doc::text($text), $status, $this->clock->now() - 3600]);
        $id = (int) $stmt->fetchColumn();
        if ($tag !== '') {
            $this->pdo->prepare('INSERT INTO tag (slug, name) VALUES (?, ?) ON CONFLICT (slug) DO NOTHING')->execute([strtolower($tag), $tag]);
            $this->pdo->prepare('INSERT INTO article_tag (article_id, tag_id) SELECT ?, id FROM tag WHERE slug = ?')->execute([$id, strtolower($tag)]);
        }

        return $id;
    }

    private static function xml(string $text): \SimpleXMLElement
    {
        $xml = simplexml_load_string($text);
        self::assertInstanceOf(\SimpleXMLElement::class, $xml, $text);

        return $xml;
    }

    public function testTheFeedCarriesTheFullTextOfTheLatestArticles(): void
    {
        $id = $this->article('first', 'en', 'First <one>', "Full text here.\n\nSecond paragraph.", 'News');
        $this->article('zweiter', 'de', 'Zweiter', 'Deutscher Text.');
        $this->article('about', 'en', 'About', 'A page.', kind: 'page');
        $this->article('hidden', 'en', 'Hidden', 'A draft.', status: 'draft');

        $response = $this->get('/feed.atom');

        self::assertSame('application/atom+xml; charset=utf-8', $response->getHeaderLine('Content-Type'));
        $body = (string) $response->getBody();
        $feed = self::xml($body);
        self::assertSame('Edict', (string) $feed->title);
        self::assertCount(2, $feed->entry, 'articles only, public only');
        self::assertStringContainsString('xml:base="http://localhost:8080/"', $body, 'so /media/ addresses resolve');
        self::assertStringContainsString('<entry xml:lang="en">', $body);
        self::assertStringContainsString('<id>http://localhost:8080/#article-' . $id . '</id>', $body);
        self::assertStringContainsString('<title>First &lt;one&gt;</title>', $body);
        self::assertStringContainsString('<category term="news" label="News"/>', $body);
        self::assertStringContainsString('&lt;p&gt;Second paragraph.&lt;/p&gt;', $body, 'the whole article, as HTML');
        self::assertStringNotContainsString('A page.', $body);
        self::assertStringNotContainsString('A draft.', $body);
    }

    public function testFeedsPerLanguageAndPerTag(): void
    {
        $this->article('first', 'en', 'First', 'English.', 'News');
        $this->article('zweiter', 'de', 'Zweiter', 'Deutsch.');

        $german = self::xml((string) $this->get('/feed.atom?lang=de')->getBody());
        self::assertCount(1, $german->entry);
        self::assertSame('Edict (Deutsch)', (string) $german->title);

        $tag = self::xml((string) $this->get('/tag/news/feed.atom')->getBody());
        self::assertCount(1, $tag->entry);
        self::assertSame(404, $this->get('/tag/nonesuch/feed.atom')->getStatusCode());
        self::assertStringContainsString('href="/tag/news/feed.atom"', (string) $this->get('/tag/news')->getBody(), 'the tag page points to its feed');
    }

    public function testTheSitemapListsWhatReadersCanOpen(): void
    {
        $this->article('first', 'en', 'First', 'Text.', 'News');
        $this->article('about', 'en', 'About', 'A page.', kind: 'page');
        $this->article('hidden', 'en', 'Hidden', 'A draft.', status: 'draft');

        $sitemap = (string) $this->get('/sitemap.xml')->getBody();

        self::xml($sitemap);
        foreach (['/', '/archive', '/first', '/about', '/tag/news'] as $path) {
            self::assertStringContainsString('<loc>http://localhost:8080' . $path . '</loc>', $sitemap, $path);
        }
        self::assertStringNotContainsString('/hidden', $sitemap);
    }

    public function testATestSiteKeepsSearchEnginesOut(): void
    {
        self::assertSame("User-agent: *\nDisallow: /\n", (string) $this->get('/robots.txt')->getBody());
    }

    public function testArticlesDescribeThemselvesForLinkPreviews(): void
    {
        $this->article('first', 'de', 'Erster <Artikel>', 'Text.');

        $html = (string) $this->get('/first')->getBody();

        self::assertStringContainsString('<link rel="canonical" href="http://localhost:8080/first">', $html);
        self::assertStringContainsString('<meta property="og:type" content="article">', $html);
        self::assertStringContainsString('<meta property="og:title" content="Erster &lt;Artikel&gt;">', $html);
        self::assertStringContainsString('<meta name="description" content="A teaser &amp; more">', $html);
        self::assertStringContainsString('<meta property="og:locale" content="de_DE">', $html);
        self::assertStringContainsString('<meta name="twitter:card" content="summary">', $html, 'no cover, no large card');
        self::assertStringContainsString('<link rel="alternate" type="application/atom+xml"', $html);
        self::assertStringContainsString('<meta name="robots" content="noindex">', (string) $this->get('/search?q=x')->getBody());
    }

    public function testErrorPagesHaveTheSitesLookOrAPlainOneWithoutIt(): void
    {
        $page = new ErrorPage($this->service(Services::class));
        $notFound = new HttpNotFoundException((new ServerRequestFactory())->createServerRequest('GET', '/nope'));

        // First, before anything has read the settings: the database is the problem.
        $this->pdo->exec('DROP TABLE setting');
        try {
            $plain = $page(new \RuntimeException('database gone'), false);
        } finally {
            $this->pdo->exec('CREATE TABLE setting (key text PRIMARY KEY, value jsonb NOT NULL)');
        }
        self::assertStringContainsString('<h1>Something went wrong</h1>', $plain);
        self::assertStringNotContainsString('database gone', $plain, 'no internals for readers');
        self::assertStringNotContainsString('site-title', $plain, 'the plain page, without the theme');

        $html = $page($notFound, false);
        self::assertStringContainsString('<h1>Not found</h1>', $html);
        self::assertStringContainsString('class="site-title"', $html, 'in the theme');
        self::assertStringContainsString('<meta name="robots" content="noindex">', $html);
    }
}
