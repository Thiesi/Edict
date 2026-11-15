<?php

declare(strict_types=1);

namespace Edict\Tests\Content;

use Edict\Content\ArticleDate;
use Edict\Content\ViewChart;
use Edict\Content\Views;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ViewsUnitTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function readers(): iterable
    {
        yield 'Firefox' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:132.0) Gecko/20100101 Firefox/132.0'];
        yield 'Chrome' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36'];
        yield 'Safari on iPhone' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 18_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.1 Mobile/15E148 Safari/604.1'];
        yield 'Edge' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36 Edg/131.0.0.0'];
        yield 'Samsung Internet' => ['Mozilla/5.0 (Linux; Android 14; SM-S918B) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/26.0 Chrome/122.0.0.0 Mobile Safari/537.36'];
        yield 'Firefox on Android' => ['Mozilla/5.0 (Android 14; Mobile; rv:132.0) Gecko/132.0 Firefox/132.0'];
    }

    #[DataProvider('readers')]
    public function testBrowsersAreReaders(string $agent): void
    {
        self::assertFalse(Views::isBot($agent));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function bots(): iterable
    {
        yield 'none' => [''];
        yield 'blank' => ['   '];
        yield 'Googlebot' => ['Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)'];
        yield 'Googlebot smartphone' => ['Mozilla/5.0 (Linux; Android 6.0.1; Nexus 5X Build/MMB29P) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Mobile Safari/537.36 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)'];
        yield 'Bing' => ['Mozilla/5.0 (compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm)'];
        yield 'Yandex' => ['Mozilla/5.0 (compatible; YandexBot/3.0; +http://yandex.com/bots)'];
        yield 'Applebot' => ['Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/13.1.1 Safari/605.1.15 (Applebot/0.1; +http://www.apple.com/go/applebot)'];
        yield 'GPTBot' => ['Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; GPTBot/1.2; +https://openai.com/gptbot)'];
        yield 'Facebook preview' => ['facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)'];
        yield 'WhatsApp preview' => ['WhatsApp/2.23.20.0'];
        yield 'Discord preview' => ['Mozilla/5.0 (compatible; Discordbot/2.0; +https://discordapp.com)'];
        yield 'Mastodon preview' => ['Mastodon/4.3.1 (http.rb/5.2.0; +https://social.example/)'];
        yield 'Feed reader' => ['Feedly/1.0 (+http://www.feedly.com/fetcher.html; 3 subscribers)'];
        yield 'Headless Chrome' => ['Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/131.0.0.0 Safari/537.36'];
        yield 'Lighthouse' => ['Mozilla/5.0 (Linux; Android 11; moto g power (2022)) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Mobile Safari/537.36 Chrome-Lighthouse'];
        yield 'Uptime monitor' => ['Mozilla/5.0+(compatible; UptimeRobot/2.0; http://www.uptimerobot.com/)'];
        yield 'curl' => ['curl/8.10.1'];
        yield 'wget' => ['Wget/1.24.5'];
        yield 'Python' => ['python-requests/2.32.3'];
        yield 'Go' => ['Go-http-client/2.0'];
        yield 'Guzzle' => ['GuzzleHttp/7'];
        yield 'Scanner' => ['Mozilla/5.0 zgrab/0.x'];
    }

    #[DataProvider('bots')]
    public function testBotsAreNotReaders(string $agent): void
    {
        self::assertTrue(Views::isBot($agent));
    }

    public function testTheDayIsBerlins(): void
    {
        // 22:59 and 23:00 UTC on 15 January: 23:59 and midnight in Berlin.
        self::assertSame('2027-01-15', ArticleDate::day(1_800_053_940));
        self::assertSame('2027-01-16', ArticleDate::day(1_800_054_000));
        // In summer Berlin is two hours ahead: 22:00 UTC on 30 June is already 1 July.
        self::assertSame('2026-07-01', ArticleDate::day((int) gmmktime(22, 0, 0, 6, 30, 2026)));
    }

    public function testTheChartScalesToTheBusiestDay(): void
    {
        $days = [];
        foreach (range(1, 30) as $n) {
            $days[] = ['day' => sprintf('2026-11-%02d', $n), 'views' => match ($n) {
                1 => 10,
                2 => 5,
                3 => 1,
                30 => 40,
                default => 0,
            }];
        }

        $chart = ViewChart::build($days);

        self::assertSame(56, $chart['total']);
        self::assertSame(40, $chart['peak']);
        self::assertSame('1 Nov', $chart['first']);
        self::assertSame('30 Nov', $chart['last']);
        self::assertCount(30, $chart['bars']);
        self::assertSame(10.0, $chart['bars'][1]['x']);
        self::assertSame(10.0, $chart['bars'][1]['slot']);
        self::assertNull($chart['bars'][3]['path'], 'an empty day has no bar');

        // A bar 8 wide with a gap of 1 on each side, rounded at the top, on the baseline.
        self::assertSame('M291 72V2Q291 0 293 0H297Q299 0 299 2V72Z', $chart['bars'][29]['path']);
        self::assertSame('M1 72V56Q1 54 3 54H7Q9 54 9 56V72Z', $chart['bars'][0]['path'], 'a quarter of the busiest day');
        self::assertSame('M21 72V72Q21 70.2 22.8 70.2H27.2Q29 70.2 29 72V72Z', $chart['bars'][2]['path'], 'tiny, but there');
    }

    public function testAnEmptyChartIsFlat(): void
    {
        $chart = ViewChart::build([['day' => '2026-11-15', 'views' => 0]]);

        self::assertSame(0, $chart['total']);
        self::assertSame(0, $chart['peak']);
        self::assertNull($chart['bars'][0]['path']);
        self::assertSame(0, ViewChart::build([])['total']);
    }
}
