<?php

declare(strict_types=1);

namespace Edict\Tests\Content;

use Edict\Content\Article;
use Edict\Content\Body;
use Edict\Content\StructuredData;
use Edict\Site\SiteSettings;
use PHPUnit\Framework\TestCase;

final class StructuredDataTest extends TestCase
{
    private const BASE = 'https://edict.example';

    // 2027-01-15 11:00 UTC is 12:00 in Berlin (winter), 2027-07-01 08:00 UTC is 10:00 (summer).
    private const PUBLISHED = 1_800_010_800;
    private const REVISED = 1_814_428_800;

    private static function site(string $description = 'Decrees & musings'): SiteSettings
    {
        return new SiteSettings('decree', 'My <Realm>', $description, [], [], '', '', '');
    }

    private static function article(string $kind = 'article', string $title = 'Hello', string $teaser = 'A teaser', ?int $revised = null): Article
    {
        return new Article(7, $kind, 'hello', 'de', $title, $teaser, Body::EMPTY, 'published', self::PUBLISHED, self::PUBLISHED, null, [], null, 1, revisedAt: $revised);
    }

    /**
     * @return array<string, mixed>
     */
    private static function decoded(string $script): array
    {
        $json = preg_replace('~^<script type="application/ld\+json">(.*)</script>$~Ds', '$1', $script, -1, $count);
        self::assertSame(1, $count, $script);
        self::assertIsString($json);
        $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($data);

        /** @var array<string, mixed> $data */
        return $data;
    }

    public function testAnArticleIsABlogPosting(): void
    {
        $image = ['url' => self::BASE . '/media/ab/cd/x-w960.jpg', 'width' => 960, 'height' => 540];

        $data = StructuredData::article(self::article(revised: self::REVISED), self::site(), self::BASE, $image, 'Thiesi');

        self::assertSame([
            '@context' => 'https://schema.org',
            '@type' => 'BlogPosting',
            'headline' => 'Hello',
            'description' => 'A teaser',
            'datePublished' => '2027-01-15T12:00:00+01:00',
            'dateModified' => '2027-07-01T10:00:00+02:00',
            'image' => ['@type' => 'ImageObject', 'url' => self::BASE . '/media/ab/cd/x-w960.jpg', 'width' => 960, 'height' => 540],
            'inLanguage' => 'de',
            'url' => self::BASE . '/hello',
            'mainEntityOfPage' => ['@type' => 'WebPage', '@id' => self::BASE . '/hello'],
            'author' => ['@type' => 'Person', 'name' => 'Thiesi'],
            'publisher' => ['@type' => 'Organization', 'name' => 'My <Realm>', 'url' => self::BASE . '/'],
        ], $data);
    }

    public function testWithoutAnOwnerNameOrPicturesTheSiteIsTheAuthor(): void
    {
        $data = StructuredData::article(self::article(teaser: ''), self::site(), self::BASE, null, null);

        self::assertSame(['@type' => 'Organization', 'name' => 'My <Realm>', 'url' => self::BASE . '/'], $data['author']);
        self::assertArrayNotHasKey('image', $data);
        self::assertSame('2027-01-15T12:00:00+01:00', $data['dateModified'], 'never revised: the published date');
        self::assertSame('Decrees & musings', $data['description'], 'no teaser: the description the page carries in its meta tag');

        $bare = StructuredData::article(self::article(teaser: ''), self::site(''), self::BASE, null, null);
        self::assertArrayNotHasKey('description', $bare);
    }

    public function testAPageIsAWebPage(): void
    {
        $data = StructuredData::article(self::article('page'), self::site(), self::BASE, null, null);

        self::assertSame('WebPage', $data['@type']);
        self::assertSame(self::BASE . '/hello', $data['url']);
        self::assertArrayNotHasKey('mainEntityOfPage', $data, 'the page is the WebPage itself');
    }

    public function testTheHomePageIsAWebSiteWithItsSearch(): void
    {
        self::assertSame([
            '@context' => 'https://schema.org',
            '@type' => 'WebSite',
            'name' => 'My <Realm>',
            'description' => 'Decrees & musings',
            'url' => self::BASE . '/',
            'inLanguage' => ['de', 'en'],
            'potentialAction' => [
                '@type' => 'SearchAction',
                'target' => ['@type' => 'EntryPoint', 'urlTemplate' => self::BASE . '/search?q={search_term_string}'],
                'query-input' => 'required name=search_term_string',
            ],
        ], StructuredData::website(self::site(), self::BASE, ['de', 'en']));

        self::assertSame('de', StructuredData::website(self::site(), self::BASE, ['de'])['inLanguage']);
        $empty = StructuredData::website(self::site(''), self::BASE, []);
        self::assertArrayNotHasKey('inLanguage', $empty, 'nothing published yet');
        self::assertArrayNotHasKey('description', $empty);
    }

    public function testNothingInATitleCanEndTheScriptElement(): void
    {
        $title = '</script><script>alert(1)</script> <!-- & \'quotes\' "too" Über/Straße';

        $script = StructuredData::script(StructuredData::article(self::article(title: $title), self::site(), self::BASE, null, null));

        self::assertSame(1, substr_count($script, '</script>'), 'only the element\'s own end tag');
        self::assertSame(2, substr_count($script, '<'), 'only the element\'s own two tags');
        self::assertStringNotContainsString('<!--', $script);
        self::assertStringContainsString('</script>', $script);
        self::assertStringContainsString('Über/Straße', $script, 'readable Unicode and slashes otherwise');
        self::assertStringNotContainsString("'", substr($script, strlen('<script type="application/ld+json">')));
        self::assertSame($title, self::decoded($script)['headline'], 'and it decodes to the same title');
    }
}
