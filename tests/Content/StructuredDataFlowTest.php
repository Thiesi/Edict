<?php

declare(strict_types=1);

namespace Edict\Tests\Content;

use Edict\Auth\AdminAccount;
use Edict\Auth\SessionStore;
use Edict\Http\Middleware\SecurityHeadersMiddleware;
use Edict\Tests\DbTestCase;

final class StructuredDataFlowTest extends DbTestCase
{
    private const SESSION = 'edict_session';

    /** @var array<string, string> */
    private array $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service(AdminAccount::class)->create('sovereign', 'correct horse battery');
        $id = $this->pdo->query('SELECT id FROM app_user WHERE is_admin');
        self::assertNotFalse($id);
        $this->admin = [self::SESSION => $this->service(SessionStore::class)->create((int) $id->fetchColumn(), 86400)];
    }

    private function article(string $slug, string $title, string $kind = 'article', string $lang = 'de', string $teaser = 'A teaser & more', ?int $cover = null, ?int $revised = null, string $status = 'published'): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO article (kind, slug, lang, title, teaser, body_json, status, published_at, revised_at, cover_media_id, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, to_timestamp(?), to_timestamp(?), ?, now(), now()) RETURNING id',
        );
        $stmt->execute([$kind, $slug, $lang, $title, $teaser, Doc::text('Some text.'), $status, $this->clock->now() - 3600, $revised, $cover]);
        $id = (int) $stmt->fetchColumn();
        $this->pdo->prepare('INSERT INTO tag (slug, name) VALUES (?, ?) ON CONFLICT (slug) DO NOTHING')->execute(['news', 'News']);
        $this->pdo->prepare("INSERT INTO article_tag (article_id, tag_id) SELECT ?, id FROM tag WHERE slug = 'news'")->execute([$id]);

        return $id;
    }

    private function imageRow(): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO media (sha256, kind, extension, bytes, original_name, width, height, status, created_at)
             VALUES (?, 'image', 'jpg', 1000, 'x.jpg', 1600, 900, 'ready', now()) RETURNING id",
        );
        $stmt->execute([hash('sha256', 'cover')]);
        $id = (int) $stmt->fetchColumn();
        $insert = $this->pdo->prepare('INSERT INTO media_variant (media_id, preset, format, width, height, bytes) VALUES (?, ?, ?, ?, ?, 10)');
        $insert->execute([$id, 'w960-jpg', 'jpg', 960, 540]);
        $insert->execute([$id, 'full', 'jpg', 1600, 900]);

        return $id;
    }

    /**
     * The JSON-LD blocks in the page's head, decoded.
     *
     * @return list<array<string, mixed>>
     */
    private static function jsonLd(string $html): array
    {
        $head = strstr($html, '</head>', true);
        self::assertIsString($head);
        preg_match_all('~<script type="application/ld\+json">(.*?)</script>~s', $html, $all);
        preg_match_all('~<script type="application/ld\+json">(.*?)</script>~s', $head, $inHead);
        self::assertSame($all[1], $inHead[1], 'only in the head');

        return array_map(static function (string $json): array {
            $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
            self::assertIsArray($data);

            /** @var array<string, mixed> $data */
            return $data;
        }, $all[1]);
    }

    private static function attribute(string $html, string $pattern): string
    {
        self::assertSame(1, preg_match($pattern, $html, $match), $pattern);

        return html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5);
    }

    public function testAnArticleDescribesItselfAsABlogPosting(): void
    {
        $revised = $this->clock->now() + 86400 * 180;
        $this->article('erster', 'Erster <Artikel>', cover: $this->imageRow(), revised: $revised);

        $response = $this->get('/erster');
        $html = (string) $response->getBody();
        $blocks = self::jsonLd($html);

        self::assertCount(1, $blocks);
        $data = $blocks[0];
        self::assertSame('https://schema.org', $data['@context']);
        self::assertSame('BlogPosting', $data['@type']);
        self::assertSame('Erster <Artikel>', $data['headline']);
        self::assertSame('A teaser & more', $data['description']);
        self::assertSame('2027-01-15T08:00:00+01:00', $data['datePublished'], 'Berlin time, with its offset');
        self::assertSame('2027-07-14T10:00:00+02:00', $data['dateModified'], 'the revision, in summer time');
        self::assertSame('de', $data['inLanguage']);
        self::assertSame(self::attribute($html, '~<link rel="canonical" href="([^"]+)">~'), $data['url']);
        self::assertSame(self::ORIGIN . '/erster', $data['url']);
        self::assertSame(['@type' => 'WebPage', '@id' => self::ORIGIN . '/erster'], $data['mainEntityOfPage']);
        self::assertSame(['@type' => 'Organization', 'name' => 'Edict', 'url' => self::ORIGIN . '/'], $data['publisher']);

        self::assertIsArray($data['image']);
        self::assertSame(self::attribute($html, '~<meta property="og:image" content="([^"]+)">~'), $data['image']['url'], 'the share image');
        self::assertStringStartsWith(self::ORIGIN . '/media/', $data['image']['url']);
        self::assertSame([1600, 900], [$data['image']['width'], $data['image']['height']]);

        self::assertSame(SecurityHeadersMiddleware::POLICY, $response->getHeaderLine('Content-Security-Policy'), 'the policy stays as strict');
    }

    public function testTheAuthorIsTheOwnersNameButNeverTheLoginUsername(): void
    {
        $this->article('erster', 'Erster');

        $data = self::jsonLd((string) $this->get('/erster')->getBody())[0];
        self::assertSame(['@type' => 'Organization', 'name' => 'Edict', 'url' => self::ORIGIN . '/'], $data['author'], 'the display name is still the username');
        self::assertStringNotContainsString('sovereign', (string) $this->get('/erster')->getBody());

        $this->pdo->exec("UPDATE app_user SET display_name = 'Sovereign' WHERE is_admin");
        self::assertSame(['@type' => 'Organization', 'name' => 'Edict', 'url' => self::ORIGIN . '/'], self::jsonLd((string) $this->get('/erster')->getBody())[0]['author'], 'in any case');

        $this->pdo->exec("UPDATE app_user SET display_name = 'Thiesi' WHERE is_admin");
        self::assertSame(['@type' => 'Person', 'name' => 'Thiesi'], self::jsonLd((string) $this->get('/erster')->getBody())[0]['author']);
    }

    public function testAPageDescribesItselfAsAWebPage(): void
    {
        $this->article('about', 'About', kind: 'page', lang: 'en', teaser: '');

        $data = self::jsonLd((string) $this->get('/about')->getBody());

        self::assertCount(1, $data);
        self::assertSame('WebPage', $data[0]['@type']);
        self::assertSame('About', $data[0]['headline']);
        self::assertSame('en', $data[0]['inLanguage']);
        self::assertSame(self::ORIGIN . '/about', $data[0]['url']);
        self::assertSame('2027-01-15T08:00:00+01:00', $data[0]['dateModified'], 'never revised: the published date');
        self::assertArrayNotHasKey('description', $data[0], 'no teaser and no site description');
        self::assertArrayNotHasKey('image', $data[0]);
        self::assertArrayNotHasKey('mainEntityOfPage', $data[0]);
    }

    public function testTheHomePageDescribesTheSiteAndItsSearch(): void
    {
        $this->article('erster', 'Erster');
        $this->article('about', 'About', kind: 'page', lang: 'en');
        $this->article('later', 'Later', lang: 'en', status: 'draft');

        $data = self::jsonLd((string) $this->get('/')->getBody());

        self::assertSame([[
            '@context' => 'https://schema.org',
            '@type' => 'WebSite',
            'name' => 'Edict',
            'url' => self::ORIGIN . '/',
            'inLanguage' => ['de', 'en'],
            'potentialAction' => [
                '@type' => 'SearchAction',
                'target' => ['@type' => 'EntryPoint', 'urlTemplate' => self::ORIGIN . '/search?q={search_term_string}'],
                'query-input' => 'required name=search_term_string',
            ],
        ]], $data);

        $this->pdo->exec("DELETE FROM article WHERE lang = 'en'");
        self::assertSame('de', self::jsonLd((string) $this->get('/')->getBody())[0]['inLanguage'], 'only what readers find');
    }

    public function testNoneOnPreviewsErrorsSearchesOrLists(): void
    {
        $id = $this->article('erster', 'Erster');
        $this->article('entwurf', 'Entwurf', status: 'draft');

        $preview = $this->get("/admin/articles/$id/preview", $this->admin);
        self::assertSame(200, $preview->getStatusCode());
        self::assertStringContainsString('<strong>Preview.</strong>', (string) $preview->getBody());

        $pages = [
            'the preview' => $preview,
            'a missing page' => $this->get('/nonesuch'),
            'a draft' => $this->get('/entwurf'),
            'the search' => $this->get('/search?q=Erster'),
            'the archive' => $this->get('/archive'),
            'a tag' => $this->get('/tag/news'),
        ];
        foreach ($pages as $name => $response) {
            self::assertStringNotContainsString('application/ld+json', (string) $response->getBody(), $name);
        }
        self::assertStringContainsString('Erster', (string) $pages['the search']->getBody(), 'the search found it');
        self::assertCount(1, self::jsonLd((string) $this->get('/erster', $this->admin)->getBody()), 'the admin reading it live gets it as readers do');
    }

    public function testATitleCannotBreakOutOfTheScriptElement(): void
    {
        $title = '</script><script>alert(1)</script><!--';
        $this->article('boese', $title);

        $html = (string) $this->get('/boese')->getBody();

        self::assertStringNotContainsString('<script>alert(1)', $html);
        self::assertStringNotContainsString('<!--', $html);
        self::assertSame(1, substr_count($html, '<script type="application/ld+json">'));
        self::assertSame($title, self::jsonLd($html)[0]['headline']);
    }
}
