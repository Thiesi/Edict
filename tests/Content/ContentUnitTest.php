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

    public function testBodyRoundTripsPlainText(): void
    {
        $text = "First line\nsecond line\n\nNext paragraph";

        self::assertSame($text, Body::toPlainText(Body::fromPlainText($text)));
        self::assertSame($text, Body::toPlainText(Body::fromPlainText("\r\n" . str_replace("\n", "\r\n", $text) . "\r\n\r\n")));
    }

    public function testEmptyBodyIsAnEmptyDocument(): void
    {
        self::assertSame('{"type":"doc","content":[]}', Body::fromPlainText("  \n "));
        self::assertSame('', Body::toHtml(Body::fromPlainText('')));
    }

    public function testBodyHtmlEscapesEverything(): void
    {
        $html = Body::toHtml(Body::fromPlainText("<script>alert('x')</script> & more\nline two"));

        self::assertStringNotContainsString('<script', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
        self::assertStringContainsString('&amp; more<br>line two', $html);
    }

    public function testBodyHtmlLeavesOutUnknownNodes(): void
    {
        $json = (string) json_encode(['type' => 'doc', 'content' => [
            ['type' => 'rawHtml', 'html' => '<script>x</script>'],
            ['type' => 'paragraph', 'content' => [
                ['type' => 'text', 'text' => 'kept'],
                ['type' => 'evil', 'text' => '<img onerror=x>'],
                'not a node',
            ]],
            'not a node',
        ]]);

        self::assertSame("<p>kept</p>\n", Body::toHtml($json));
        self::assertSame('', Body::toHtml('not json'));
        self::assertSame('', Body::toHtml('"a string"'));
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
        $input = new ArticleInput('t', '', 'en', '', '', ' PHP , php,  Web  Dev,,???, Größe ');

        self::assertSame([
            ['slug' => 'php', 'name' => 'PHP'],
            ['slug' => 'web-dev', 'name' => 'Web Dev'],
            ['slug' => 'groesse', 'name' => 'Größe'],
        ], $input->tagList());
    }

    /**
     * @return iterable<string, array{array<string, string>, string}>
     */
    public static function badInputs(): iterable
    {
        $good = ['title' => 'T', 'slug' => '', 'lang' => 'en', 'teaser' => '', 'body' => '', 'tags' => ''];

        yield 'empty title' => [['title' => '   '] + $good, 'title'];
        yield 'long title' => [['title' => str_repeat('x', 201)] + $good, 'title'];
        yield 'invalid title bytes' => [['title' => "\xFF"] + $good, 'title'];
        yield 'bad slug' => [['slug' => 'Not Valid'] + $good, 'slug'];
        yield 'reserved slug' => [['slug' => 'admin'] + $good, 'slug'];
        yield 'bad language' => [['lang' => 'fr'] + $good, 'lang'];
        yield 'long teaser' => [['teaser' => str_repeat('x', 1001)] + $good, 'teaser'];
        yield 'long body' => [['body' => str_repeat('x', 2_000_001)] + $good, 'body'];
        yield 'too many tags' => [['tags' => implode(',', array_map(strval(...), range(1, 21)))] + $good, 'tags'];
        yield 'long tag' => [['tags' => str_repeat('x', 65)] + $good, 'tags'];
    }

    /**
     * @param array<string, string> $form
     */
    #[DataProvider('badInputs')]
    public function testBadInputIsRefused(array $form, string $field): void
    {
        self::assertArrayHasKey($field, ArticleInput::fromForm($form)->errors());
    }

    public function testGoodInputPasses(): void
    {
        $input = ArticleInput::fromForm(['title' => ' T ', 'slug' => 'a-b', 'lang' => 'de', 'teaser' => '', 'body' => 'x', 'tags' => 'y']);

        self::assertSame([], $input->errors());
        self::assertSame('T', $input->title);
    }
}
