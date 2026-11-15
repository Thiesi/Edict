<?php

declare(strict_types=1);

namespace Edict\Content;

use Edict\Clock;
use Edict\Database\Row;

/**
 * View counts for the admin: one number per article and day, without cookies, scripts
 * or anything stored about the reader.
 *
 * A reader counts once per article and day. That is told by a hash of a random daily
 * salt, the IP address, the user agent and the article. Only the hash is kept, and the
 * day after, the salt and the hashes are deleted, so nothing left can be traced back
 * to a reader.
 */
final readonly class Views
{
    /** How many days the "recent" numbers cover, today included. */
    public const RECENT_DAYS = 30;

    /**
     * Crawlers, previewers, monitors and scripts. They say so in their user agent,
     * and a request without one is never a person reading.
     */
    private const BOTS = '~bot|crawl|spider|slurp|scrape|fetch|archiv|preview|monitor|uptime|pingdom|lighthouse|headless'
        . '|facebookexternalhit|embedly|whatsapp|telegram|discord|slack|skype|mastodon|akkoma|pleroma|misskey'
        . '|feed|rss|validator|scan|python|curl|wget|httpie|libwww|perl|ruby|java/|okhttp|go-http|axios|node-fetch'
        . '|guzzle|php/|http_request|httpclient|ahrefs|semrush|mj12|petalsearch|bytespider|ccbot|gptbot|claude'
        . '|anthropic|perplexity|yandex|baidu|sogou|exabot|ia_archiver|zgrab|masscan|nmap|nikto|sqlmap~i';

    public function __construct(private \PDO $pdo, private Clock $clock) {}

    public static function isBot(string $userAgent): bool
    {
        return trim($userAgent) === '' || preg_match(self::BOTS, $userAgent) === 1;
    }

    /**
     * Counts a view of this article, unless this reader has been counted for it today.
     * The caller has already left out the admin, bots and anything but a real page view.
     *
     * @return bool whether it counted
     */
    public function record(int $articleId, string $ip, string $userAgent): bool
    {
        $day = ArticleDate::day($this->clock->now());
        $hash = hash('sha256', $this->salt($day) . "\0" . $ip . "\0" . $userAgent . "\0" . $articleId);

        // The seen-check and the count in one statement: a hash already there inserts
        // nothing, and then nothing is counted.
        $stmt = $this->pdo->prepare(
            "WITH seen AS (
                 INSERT INTO view_seen (hash, day) VALUES (decode(?, 'hex'), ?::date) ON CONFLICT DO NOTHING RETURNING 1
             )
             INSERT INTO article_view_day (article_id, day, views)
             SELECT ?, ?::date, 1 FROM seen
             ON CONFLICT (article_id, day) DO UPDATE SET views = article_view_day.views + 1",
        );
        $stmt->execute([$hash, $day, $articleId, $day]);

        return $stmt->rowCount() === 1;
    }

    /**
     * Today's salt. The first view of a day makes it, and forgets the days before.
     */
    private function salt(string $day): string
    {
        $select = $this->pdo->prepare("SELECT encode(salt, 'hex') FROM view_salt WHERE day = ?::date");
        $select->execute([$day]);
        $salt = $select->fetchColumn();
        if (is_string($salt)) {
            return (string) hex2bin($salt);
        }

        // Two first views at once: the second insert does nothing, and both read the same salt.
        $this->pdo->prepare("INSERT INTO view_salt (day, salt) VALUES (?::date, decode(?, 'hex')) ON CONFLICT DO NOTHING")
            ->execute([$day, bin2hex(random_bytes(32))]);
        $this->forgetPastDays();

        $select->execute([$day]);
        $salt = $select->fetchColumn();
        if (!is_string($salt)) {
            throw new \LogicException('The salt for today was just stored');
        }

        return (string) hex2bin($salt);
    }

    /**
     * Deletes the salts and seen-hashes of every day before today. Also run by the
     * worker, so a day without views doesn't keep yesterday's salt.
     */
    public function forgetPastDays(): void
    {
        $today = ArticleDate::day($this->clock->now());
        $this->pdo->prepare('DELETE FROM view_seen WHERE day < ?::date')->execute([$today]);
        $this->pdo->prepare('DELETE FROM view_salt WHERE day < ?::date')->execute([$today]);
    }

    /**
     * All views and those of the recent days, per article, for the admin.
     *
     * @param list<int> $articleIds
     *
     * @return array<int, array{total: int, recent: int}> only articles that have views
     */
    public function counts(array $articleIds): array
    {
        if ($articleIds === []) {
            return [];
        }
        $stmt = $this->pdo->prepare(
            'SELECT article_id, sum(views) AS total, coalesce(sum(views) FILTER (WHERE day > ?::date - ?::int), 0) AS recent
             FROM article_view_day WHERE article_id = ANY(?::bigint[]) GROUP BY article_id',
        );
        $stmt->execute([ArticleDate::day($this->clock->now()), self::RECENT_DAYS, '{' . implode(',', $articleIds) . '}']);
        $counts = [];
        foreach ($stmt->fetchAll() as $row) {
            if (is_array($row)) {
                $counts[Row::int($row, 'article_id')] = ['total' => Row::int($row, 'total'), 'recent' => Row::int($row, 'recent')];
            }
        }

        return $counts;
    }

    /**
     * Views of all articles and pages per day, for the recent days, oldest first,
     * with the days without views as zero.
     *
     * @return list<array{day: string, views: int}>
     */
    public function daily(): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT to_char(d.day, 'YYYY-MM-DD') AS day, coalesce(sum(v.views), 0) AS views
             FROM (SELECT ?::date - back AS day FROM generate_series(0, ?::int - 1) AS back) AS d
             LEFT JOIN article_view_day v ON v.day = d.day
             GROUP BY d.day ORDER BY d.day",
        );
        $stmt->execute([ArticleDate::day($this->clock->now()), self::RECENT_DAYS]);
        $days = [];
        foreach ($stmt->fetchAll() as $row) {
            if (is_array($row)) {
                $days[] = ['day' => Row::str($row, 'day'), 'views' => Row::int($row, 'views')];
            }
        }

        return $days;
    }
}
