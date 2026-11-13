<?php

declare(strict_types=1);

namespace Edict\Http;

use Edict\Config;
use Edict\Content\Article;
use Edict\Content\Articles;
use Edict\Content\BodyRenderer;
use Edict\Site\Settings;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;

/**
 * Atom feeds with the full text, the sitemap and robots.txt: what machines read.
 */
final readonly class FeedController
{
    private const ENTRIES = 20;
    private const SITEMAP_LIMIT = 10_000;

    public function __construct(
        private Articles $articles,
        private BodyRenderer $renderer,
        private Settings $settings,
        private Config $config,
    ) {}

    public function site(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $lang = $request->getQueryParams()['lang'] ?? null;
        $lang = $lang === 'de' || $lang === 'en' ? $lang : null;
        $title = $this->settings->site()->title . ($lang === null ? '' : ($lang === 'de' ? ' (Deutsch)' : ' (English)'));

        return $this->atom($response, $title, '/feed.atom' . ($lang === null ? '' : '?lang=' . $lang), '/', $this->articles->publicList(self::ENTRIES, 0, $lang));
    }

    /**
     * @param array<string, string> $args
     */
    public function tag(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $slug = $args['slug'] ?? '';
        $name = $this->articles->tagName($slug);
        if ($name === null) {
            throw new HttpNotFoundException($request);
        }

        return $this->atom($response, $this->settings->site()->title . ': ' . $name, '/tag/' . $slug . '/feed.atom', '/tag/' . $slug, $this->articles->publicListByTag($slug, self::ENTRIES, 0));
    }

    public function sitemap(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $base = $this->config->appUrl;
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        $xml .= self::url($base . '/', null) . self::url($base . '/archive', null);
        foreach ($this->articles->sitemap(self::SITEMAP_LIMIT) as $entry) {
            $xml .= self::url($base . '/' . $entry['slug'], $entry['updated']);
        }
        foreach ($this->articles->tags() as $tag) {
            if ($tag['count'] > 0) {
                $xml .= self::url($base . '/tag/' . $tag['slug'], null);
            }
        }
        $response->getBody()->write($xml . "</urlset>\n");

        return $response->withHeader('Content-Type', 'application/xml; charset=utf-8')->withHeader('Cache-Control', 'public, max-age=3600');
    }

    /**
     * Staging and other test sites keep out of search engines entirely.
     */
    public function robots(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $text = $this->config->env === 'production'
            ? "User-agent: *\nDisallow: /admin\nDisallow: /account\nDisallow: /auth/\nDisallow: /search\nDisallow: /login\n\nSitemap: " . $this->config->appUrl . "/sitemap.xml\n"
            : "User-agent: *\nDisallow: /\n";
        $response->getBody()->write($text);

        return $response->withHeader('Content-Type', 'text/plain; charset=utf-8');
    }

    /**
     * @param list<Article> $articles
     */
    private function atom(ResponseInterface $response, string $title, string $self, string $alternate, array $articles): ResponseInterface
    {
        $base = $this->config->appUrl;
        $updated = 0;
        foreach ($articles as $article) {
            $updated = max($updated, $article->revisedAt ?? $article->publishedAt ?? 0);
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<feed xmlns="http://www.w3.org/2005/Atom" xml:base="' . self::e($base . '/') . '">' . "\n"
            . '<title>' . self::e($title) . "</title>\n"
            . ($this->settings->site()->description === '' ? '' : '<subtitle>' . self::e($this->settings->site()->description) . "</subtitle>\n")
            . '<id>' . self::e($base . $self) . "</id>\n"
            . '<link rel="self" type="application/atom+xml" href="' . self::e($base . $self) . '"/>' . "\n"
            . '<link rel="alternate" type="text/html" href="' . self::e($base . $alternate) . '"/>' . "\n"
            . '<updated>' . gmdate(DATE_ATOM, $updated) . "</updated>\n"
            . '<author><name>' . self::e($this->settings->site()->title) . "</name></author>\n"
            . '<generator uri="https://github.com/Thiesi/Edict">Edict</generator>' . "\n";

        foreach ($articles as $article) {
            $url = $base . '/' . $article->slug;
            $published = $article->publishedAt ?? 0;
            $html = ($article->teaser === '' ? '' : '<p><em>' . htmlspecialchars($article->teaser, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '</em></p>')
                . $this->renderer->cover($article)
                . $this->renderer->html($article->bodyJson);
            $xml .= '<entry xml:lang="' . self::e($article->lang) . '">' . "\n"
                // Stable even if the address changes: the article's number, not its slug.
                . '<id>' . self::e($base . '/#article-' . $article->id) . "</id>\n"
                . '<title>' . self::e($article->title) . "</title>\n"
                . '<link rel="alternate" type="text/html" href="' . self::e($url) . '"/>' . "\n"
                . '<published>' . gmdate(DATE_ATOM, $published) . "</published>\n"
                . '<updated>' . gmdate(DATE_ATOM, $article->revisedAt ?? $published) . "</updated>\n";
            foreach ($article->tags as $tag) {
                $xml .= '<category term="' . self::e($tag['slug']) . '" label="' . self::e($tag['name']) . '"/>' . "\n";
            }
            if ($article->teaser !== '') {
                $xml .= '<summary>' . self::e($article->teaser) . "</summary>\n";
            }
            $xml .= '<content type="html">' . self::e($html) . "</content>\n</entry>\n";
        }
        $response->getBody()->write($xml . "</feed>\n");

        return $response->withHeader('Content-Type', 'application/atom+xml; charset=utf-8')->withHeader('Cache-Control', 'public, max-age=600');
    }

    private static function url(string $loc, ?int $updated): string
    {
        return '<url><loc>' . self::e($loc) . '</loc>' . ($updated === null ? '' : '<lastmod>' . gmdate('Y-m-d', $updated) . '</lastmod>') . "</url>\n";
    }

    private static function e(string $text): string
    {
        // XML 1.0 has no place for most control characters, whatever an article contains.
        return htmlspecialchars((string) preg_replace('~[\x00-\x08\x0B\x0C\x0E-\x1F]~', '', $text), ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
