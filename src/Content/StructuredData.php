<?php

declare(strict_types=1);

namespace Edict\Content;

use Edict\Site\SiteSettings;

/**
 * Schema.org data for search engines, as JSON-LD: a BlogPosting for an article, a
 * WebPage for a page, and a WebSite with its search on the home page.
 *
 * Built as arrays and encoded here, never assembled in a template. The flags turn
 * <, >, &, ' and " into \u escapes, so nothing a title holds can close the script
 * element or start a comment inside it.
 */
final class StructuredData
{
    public const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP
        | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR;

    /**
     * @param array{url: string, width: int, height: int}|null $image the share image, as og:image has it
     * @param string|null                                      $author the owner's public name; null credits the site
     *
     * @return array<string, mixed>
     */
    public static function article(Article $article, SiteSettings $site, string $baseUrl, ?array $image, ?string $author): array
    {
        $url = $baseUrl . '/' . $article->slug;
        $isArticle = $article->kind === 'article';
        $description = $article->teaser !== '' ? $article->teaser : $site->description;
        $published = $article->publishedAt;
        $modified = $article->revisedAt ?? $published;

        return array_filter([
            '@context' => 'https://schema.org',
            '@type' => $isArticle ? 'BlogPosting' : 'WebPage',
            'headline' => $article->title,
            'description' => $description !== '' ? $description : null,
            'datePublished' => $published === null ? null : ArticleDate::iso($published),
            'dateModified' => $modified === null ? null : ArticleDate::iso($modified),
            'image' => $image === null ? null : ['@type' => 'ImageObject', 'url' => $image['url'], 'width' => $image['width'], 'height' => $image['height']],
            'inLanguage' => $article->lang,
            'url' => $url,
            // A page is the WebPage itself; an article is what its page is about.
            'mainEntityOfPage' => $isArticle ? ['@type' => 'WebPage', '@id' => $url] : null,
            'author' => $author !== null ? ['@type' => 'Person', 'name' => $author] : self::organisation($site, $baseUrl),
            'publisher' => self::organisation($site, $baseUrl),
        ], static fn(mixed $value): bool => $value !== null);
    }

    /**
     * @param list<string> $languages the languages of what the site publishes
     *
     * @return array<string, mixed>
     */
    public static function website(SiteSettings $site, string $baseUrl, array $languages): array
    {
        return array_filter([
            '@context' => 'https://schema.org',
            '@type' => 'WebSite',
            'name' => $site->title,
            'description' => $site->description !== '' ? $site->description : null,
            'url' => $baseUrl . '/',
            'inLanguage' => match (count($languages)) {
                0 => null,
                1 => $languages[0],
                default => $languages,
            },
            'potentialAction' => [
                '@type' => 'SearchAction',
                'target' => ['@type' => 'EntryPoint', 'urlTemplate' => $baseUrl . '/search?q={search_term_string}'],
                'query-input' => 'required name=search_term_string',
            ],
        ], static fn(mixed $value): bool => $value !== null);
    }

    /**
     * The data as a script element for the head. Browsers don't run this script type,
     * so script-src doesn't apply to it.
     *
     * @param array<string, mixed> $data
     */
    public static function script(array $data): string
    {
        return '<script type="application/ld+json">' . json_encode($data, self::JSON_FLAGS) . '</script>';
    }

    /**
     * @return array{'@type': string, name: string, url: string}
     */
    private static function organisation(SiteSettings $site, string $baseUrl): array
    {
        return ['@type' => 'Organization', 'name' => $site->title, 'url' => $baseUrl . '/'];
    }
}
