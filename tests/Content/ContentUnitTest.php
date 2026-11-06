<?php

declare(strict_types=1);

namespace Edict\Tests\Content;

use Edict\Content\ArticleDate;
use Edict\Content\ArticleInput;
use Edict\Content\Body;
use Edict\Content\Slugs;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ContentUnitTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function titles(): iterable
    {
        yield 'plain' => ['Hello World', 'hello-world'];
        yield 'umlauts' => ['Über Größe & Maß', 'ueber-groesse-mass'];
        yield 'accents' => ['Crème Brûlée', 'creme-brulee'];
        yield 'punctuation' => ['  --What?! (Really.)-- ', 'what-really'];
        yield 'nothing usable' => ['???', ''];
        yield 'not latin' => ['日本語', ''];
    }

    #[DataProvider('titles')]
    public function testSlugsComeFromText(string $title, string $slug): void
    {
        self::assertSame($slug, Slugs::fromText($title));
    }

    public function testDerivedSlugsStayValidAtTheLengthLimit(): void
    {
        $slug = Slugs::fromText(str_repeat('a', 119) . ' b c');

        self::assertLessThanOrEqual(Slugs::MAX_LENGTH, strlen($slug));
        self::assertTrue(Slugs::isValid($slug));
    }

    public function testSlugValidity(): void
    {
        foreach (['a', 'a-b', 'a1-2b'] as $good) {
            self::assertTrue(Slugs::isValid($good), $good);
        }
        foreach (['', 'A', 'a--b', '-a', 'a-', 'a_b', 'a/b', "a\n", str_repeat('a', 121)] as $bad) {
            self::assertFalse(Slugs::isValid($bad), $bad);
        }
    }

    /**
     * Everything the editor can produce, with the HTML it must become.
     *
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function blocks(): iterable
    {
        $text = ['type' => 'text', 'text' => 'x'];
        $paragraph = ['type' => 'paragraph', 'content' => [$text]];

        yield 'paragraph' => [$paragraph, "<p>x</p>\n"];
        yield 'empty paragraph' => [['type' => 'paragraph'], "<p></p>\n"];
        yield 'hard break' => [['type' => 'paragraph', 'content' => [$text, ['type' => 'hardBreak'], $text]], "<p>x<br>x</p>\n"];
        yield 'heading' => [['type' => 'heading', 'attrs' => ['level' => 3], 'content' => [$text]], "<h3>x</h3>\n"];
        yield 'bullet list' => [
            ['type' => 'bulletList', 'content' => [['type' => 'listItem', 'content' => [$paragraph]]]],
            "<ul>\n<li><p>x</p>\n</li>\n</ul>\n",
        ];
        yield 'ordered list' => [
            ['type' => 'orderedList', 'attrs' => ['start' => 3], 'content' => [['type' => 'listItem', 'content' => [$paragraph]]]],
            "<ol start=\"3\">\n<li><p>x</p>\n</li>\n</ol>\n",
        ];
        yield 'quote' => [['type' => 'blockquote', 'content' => [$paragraph]], "<blockquote>\n<p>x</p>\n</blockquote>\n"];
        yield 'code block' => [['type' => 'codeBlock', 'attrs' => ['language' => 'php'], 'content' => [$text]], "<pre><code class=\"language-php\">x</code></pre>\n"];
        yield 'rule' => [['type' => 'horizontalRule'], "<hr>\n"];
        yield 'table' => [
            ['type' => 'table', 'content' => [['type' => 'tableRow', 'content' => [
                ['type' => 'tableHeader', 'attrs' => ['colspan' => 2], 'content' => [$paragraph]],
                ['type' => 'tableCell', 'content' => [$paragraph]],
            ]]]],
            "<table><tbody>\n<tr><th colspan=\"2\"><p>x</p>\n</th><td><p>x</p>\n</td></tr>\n</tbody></table>\n",
        ];
        yield 'marks' => [
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'x', 'marks' => [
                ['type' => 'bold'], ['type' => 'italic'], ['type' => 'strike'], ['type' => 'code'],
            ]]]],
            "<p><code><s><em><strong>x</strong></em></s></code></p>\n",
        ];
        yield 'link' => [
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'x', 'marks' => [
                ['type' => 'link', 'attrs' => ['href' => 'https://example.org/?a=1&b=2', 'target' => '_blank', 'onclick' => 'x']],
            ]]]],
            "<p><a href=\"https://example.org/?a=1&amp;b=2\" rel=\"noopener\">x</a></p>\n",
        ];
    }

    /**
     * @param array<string, mixed> $node
     */
    #[DataProvider('blocks')]
    public function testBlocksBecomeHtml(array $node, string $html): void
    {
        self::assertSame($html, Body::toHtml(Body::encode(['type' => 'doc', 'content' => [$node]])));
    }

    /**
     * @param array<string, mixed> $node
     */
    #[DataProvider('blocks')]
    public function testCleaningKeepsEverythingTheEditorMakes(array $node, string $html): void
    {
        // Except attributes nothing uses, like a link's target.
        $document = ['type' => 'doc', 'content' => [$node]];

        self::assertSame($html, Body::toHtml(Body::encode(Body::clean($document))));
        self::assertSame(Body::clean($document), Body::clean(Body::clean($document)));
    }

    public function testAllTextIsEscaped(): void
    {
        $html = Body::toHtml(Doc::text("<script>alert('x')</script> & more"));

        self::assertStringNotContainsString('<script', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
        self::assertStringContainsString('&amp; more', $html);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badLinks(): iterable
    {
        yield 'javascript' => ['javascript:alert(1)'];
        yield 'mixed case' => ['JaVaScRiPt:alert(1)'];
        yield 'with tab' => ["java\tscript:alert(1)"];
        yield 'data' => ['data:text/html,<script>x</script>'];
        yield 'vbscript' => ['vbscript:x'];
        yield 'protocol-relative' => ['//evil.example'];
        yield 'backslash' => ['/\evil.example'];
        yield 'bare word' => ['example.org'];
        yield 'relative' => ['some/path'];
        yield 'file' => ['file:///etc/passwd'];
        yield 'too long' => ['https://example.org/' . str_repeat('a', 3000)];
    }

    #[DataProvider('badLinks')]
    public function testBadLinksLoseTheirLinkButKeepTheirText(string $href): void
    {
        $document = ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [
            ['type' => 'text', 'text' => 'word', 'marks' => [['type' => 'link', 'attrs' => ['href' => $href]]]],
        ]]]];

        self::assertSame("<p>word</p>\n", Body::toHtml(Body::encode($document)));
    }

    public function testGoodLinksStay(): void
    {
        foreach (['https://example.org', 'HTTP://example.org', 'mailto:a@example.org', '/some-article', 'tel:+4930123', '#top'] as $href) {
            $document = ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [
                ['type' => 'text', 'text' => 'word', 'marks' => [['type' => 'link', 'attrs' => ['href' => $href]]]],
            ]]]];

            self::assertStringContainsString('<a href="' . $href . '"', Body::toHtml(Body::encode($document)), $href);
        }
    }

    public function testHandyLinksAreCompleted(): void
    {
        $link = static fn(string $href): string => Body::toHtml(Body::encode(['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [
            ['type' => 'text', 'text' => 'x', 'marks' => [['type' => 'link', 'attrs' => ['href' => $href]]]],
        ]]]]));

        self::assertStringContainsString('href="https://www.example.org"', $link('www.example.org'));
        self::assertStringContainsString('href="https://example.org/a%20b"', $link(' https://example.org/a b '));
    }

    public function testNodesMustSitWhereTheEditorAllowsThem(): void
    {
        $text = ['type' => 'text', 'text' => 'x'];
        $paragraph = ['type' => 'paragraph', 'content' => [$text]];

        self::assertSame(['type' => 'doc', 'content' => []], Body::clean(['type' => 'doc', 'content' => [
            $text,
            ['type' => 'hardBreak'],
            ['type' => 'listItem', 'content' => [$paragraph]],
            ['type' => 'tableRow'],
        ]]));
        self::assertSame(
            ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [$text]]]],
            Body::clean(['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [$text, $paragraph]]]]),
        );
        self::assertSame(
            ['type' => 'doc', 'content' => [['type' => 'bulletList']]],
            Body::clean(['type' => 'doc', 'content' => [['type' => 'bulletList', 'content' => [$paragraph]]]]),
        );
    }

    public function testListCanStartAtZero(): void
    {
        $list = ['type' => 'orderedList', 'attrs' => ['start' => 0], 'content' => [['type' => 'listItem', 'content' => [['type' => 'paragraph']]]]];

        self::assertStringContainsString('<ol start="0">', Body::toHtml(Body::encode(['type' => 'doc', 'content' => [$list]])));
    }

    public function testUnknownThingsAreDropped(): void
    {
        $document = ['type' => 'doc', 'content' => [
            ['type' => 'rawHtml', 'html' => '<script>x</script>'],
            ['type' => 'paragraph', 'attrs' => ['onclick' => 'x', 'style' => 'x'], 'content' => [
                ['type' => 'text', 'text' => 'kept', 'marks' => [['type' => 'evil'], 'nonsense']],
                ['type' => 'evil', 'text' => '<img onerror=x>'],
                ['type' => 'text', 'text' => ''],
                ['type' => 'text', 'text' => 42],
                'not a node',
                ['no type' => true],
            ]],
            'not a node',
        ]];

        self::assertSame(
            ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'kept']]]]],
            Body::clean($document),
        );
        self::assertSame('', Body::toHtml('not json'));
        self::assertSame('', Body::toHtml('"a string"'));
        self::assertSame(['type' => 'doc', 'content' => []], Body::clean(['type' => 'notDoc', 'content' => [['type' => 'paragraph']]]));
    }

    public function testAttributesAreChecked(): void
    {
        $text = ['type' => 'text', 'text' => 'x'];
        $node = static fn(array $node): string => Body::toHtml(Body::encode(['type' => 'doc', 'content' => [$node]]));

        self::assertSame("<p>x</p>\n", $node(['type' => 'heading', 'attrs' => ['level' => 1], 'content' => [$text]]), 'the title is the only h1');
        self::assertSame("<p>x</p>\n", $node(['type' => 'heading', 'attrs' => ['level' => '2><script>'], 'content' => [$text]]));
        self::assertSame("<pre><code>x</code></pre>\n", $node(['type' => 'codeBlock', 'attrs' => ['language' => '"><script>'], 'content' => [$text]]));
        self::assertStringContainsString('<td>', $node(['type' => 'table', 'content' => [['type' => 'tableRow', 'content' => [
            ['type' => 'tableCell', 'attrs' => ['colspan' => '2" onclick="x']],
        ]]]]));
        self::assertSame("<ol>\n</ol>\n", $node(['type' => 'orderedList', 'attrs' => ['start' => '1" onclick="x']]));
    }

    public function testDepthLimitIsDetected(): void
    {
        $deep = ['type' => 'paragraph'];
        for ($i = 0; $i < 120; ++$i) {
            $deep = ['type' => 'blockquote', 'content' => [$deep]];
        }

        self::assertTrue(Body::isTooDeep(['type' => 'doc', 'content' => [$deep]]));
        self::assertFalse(Body::isTooDeep(json_decode(Doc::text('x'), true)));
        self::assertFalse(Body::isTooDeep('not a node'));
    }

    public function testNulBytesAreRemoved(): void
    {
        self::assertStringNotContainsString("\0", Body::encode(Body::clean(json_decode(Doc::text("a\0b"), true))));

        $input = ArticleInput::fromArray(['title' => "a\0b"]);
        self::assertSame('ab', $input->title);
    }

    public function testDatesFollowTheArticleLanguage(): void
    {
        // 2026-03-05 23:30 UTC is already 6 March in Berlin.
        $timestamp = 1_772_753_400;

        self::assertSame('6. März 2026', ArticleDate::format($timestamp, 'de'));
        self::assertSame('6 March 2026', ArticleDate::format($timestamp, 'en'));
        self::assertSame('2026-03-06T00:30:00+01:00', ArticleDate::iso($timestamp));
    }

    public function testTagsAreCleanedAndDeduplicated(): void
    {
        $input = ArticleInput::fromArray(['tags' => ' PHP , php,  Web  Dev,,???, Größe ']);

        self::assertSame([
            ['slug' => 'php', 'name' => 'PHP'],
            ['slug' => 'web-dev', 'name' => 'Web Dev'],
            ['slug' => 'groesse', 'name' => 'Größe'],
        ], $input->tagList());
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function badInputs(): iterable
    {
        $good = ['title' => 'T', 'slug' => '', 'lang' => 'en', 'teaser' => '', 'tags' => ''];

        yield 'empty title' => [['title' => '   '] + $good, 'title'];
        yield 'long title' => [['title' => str_repeat('x', 201)] + $good, 'title'];
        yield 'title not a string' => [['title' => ['x']] + $good, 'title'];
        yield 'bad slug' => [['slug' => 'Not Valid'] + $good, 'slug'];
        yield 'reserved slug' => [['slug' => 'admin'] + $good, 'slug'];
        yield 'bad language' => [['lang' => 'fr'] + $good, 'lang'];
        yield 'long teaser' => [['teaser' => str_repeat('x', 1001)] + $good, 'teaser'];
        yield 'long body' => [['body' => json_decode(Doc::text(str_repeat('x', 2_000_001)), true)] + $good, 'body'];
        yield 'too many tags' => [['tags' => implode(',', array_map(strval(...), range(1, 21)))] + $good, 'tags'];
        yield 'long tag' => [['tags' => str_repeat('x', 65)] + $good, 'tags'];
    }

    /**
     * @param array<string, mixed> $data
     */
    #[DataProvider('badInputs')]
    public function testBadInputIsRefused(array $data, string $field): void
    {
        self::assertArrayHasKey($field, ArticleInput::fromArray($data)->errors());
    }

    public function testGoodInputPassesAndRoundTrips(): void
    {
        $input = ArticleInput::fromArray([
            'title' => ' T ',
            'slug' => 'a-b',
            'lang' => 'de',
            'teaser' => 't',
            'body' => json_decode(Doc::text('x'), true),
            'tags' => 'y',
        ]);

        self::assertSame([], $input->errors());
        self::assertSame('T', $input->title);
        self::assertEquals($input, ArticleInput::fromJson($input->toJson()));
        self::assertEquals(ArticleInput::fromArray([]), ArticleInput::fromJson('not json'));
    }
}
