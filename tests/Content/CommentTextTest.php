<?php

declare(strict_types=1);

namespace Edict\Tests\Content;

use Edict\Content\CommentText;
use PHPUnit\Framework\TestCase;

final class CommentTextTest extends TestCase
{
    public function testEverythingIsEscapedAndBlankLinesMakeParagraphs(): void
    {
        self::assertSame("<p>One &lt;script&gt;<br>\ntwo</p>\n<p>Three</p>\n", CommentText::html("One <script>\ntwo\n\n\nThree"));
    }

    public function testAddressesBecomeLinksWithoutTheSentenceAroundThem(): void
    {
        self::assertSame(
            '<p>(<a href="https://www.example.org/x" rel="nofollow ugc noopener">www.example.org/x</a>).</p>' . "\n",
            CommentText::html('(www.example.org/x).'),
        );
    }

    public function testNothingButWebAddressesBecomesALink(): void
    {
        $html = CommentText::html('javascript:alert(1) https://x.example/"onmouseover="y');

        self::assertStringNotContainsString('href="javascript', $html);
        self::assertStringContainsString('<a href="https://x.example/" rel="nofollow ugc noopener">https://x.example/</a>&quot;onmouseover=&quot;y', $html, 'a quote ends an address');
    }
}
