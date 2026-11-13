<?php

declare(strict_types=1);

namespace Edict\Content;

use Edict\Media\MediaLibrary;

/**
 * An article body as HTML, with its media. Media that isn't ready yet is simply left
 * out, and appears once it is.
 */
final readonly class BodyRenderer
{
    private const COLUMN_SIZES = '(max-width: 42rem) 100vw, 42rem';
    private const GALLERY_SIZES = '(max-width: 42rem) 50vw, 21rem';
    private const SUMMARY_SIZES = '8rem';

    public function __construct(private MediaLibrary $library) {}

    public function html(string $json): string
    {
        $media = $this->library->ready(Body::mediaIds(Body::decode($json)));

        return Body::toHtml($json, function (array $node) use ($media): string {
            $attrs = is_array($node['attrs'] ?? null) ? $node['attrs'] : [];
            if (($node['type'] ?? null) === 'gallery') {
                return $this->gallery($attrs, $media);
            }
            if (($node['type'] ?? null) === 'player') {
                return self::player($attrs, $media);
            }

            $image = self::lookUp($attrs, $media);
            if ($image === null) {
                return '';
            }

            return "<figure class=\"media-image\">\n"
                . self::picture($image, self::text($attrs, 'alt'), self::COLUMN_SIZES)
                . self::caption($attrs)
                . "</figure>\n";
        });
    }

    /**
     * An article's cover: across the column above the text, where it's the first thing
     * seen and so isn't lazy, or small beside it in listings.
     */
    public function cover(Article $article, bool $inListing = false): string
    {
        $id = $article->coverMediaId;
        $image = $id === null ? null : ($this->library->ready([$id])[$id] ?? null);
        if ($image === null || $image['kind'] !== 'image') {
            return '';
        }

        if ($inListing) {
            return '<div class="article-cover-thumb">' . self::picture($image, '', self::SUMMARY_SIZES, link: false) . "</div>\n";
        }

        return "<figure class=\"article-cover\">\n" . self::picture($image, $article->coverAlt, self::COLUMN_SIZES, lazy: false) . "</figure>\n";
    }

    /**
     * Native players: Apache answers their range requests, so seeking is instant. The
     * link inside is for browsers that can't play it.
     *
     * @param array<mixed> $attrs
     * @param array<int, array<string, mixed>> $media
     */
    private static function player(array $attrs, array $media): string
    {
        $found = self::lookUp($attrs, $media);
        /** @var array<string, array{preset: string, format: string, width: int, height: int, url: string}> $variants */
        $variants = array_column(is_array($found['variants'] ?? null) ? $found['variants'] : [], null, 'preset');

        $poster = array_values(array_filter($variants, static fn(array $variant): bool => MediaLibrary::isPoster($variant['preset'])))[0] ?? null;
        if (($found['kind'] ?? null) === 'video' && isset($variants['h264']) && $poster !== null) {
            $file = Body::escape($variants['h264']['url']);

            return "<figure class=\"media-video\">\n"
                . '<video controls playsinline preload="metadata" src="' . $file . '" poster="' . Body::escape($poster['url']) . '"'
                . ' width="' . $variants['h264']['width'] . '" height="' . $variants['h264']['height'] . '"><a href="' . $file . "\">Download this video</a></video>\n"
                . self::caption($attrs)
                . "</figure>\n";
        }

        if (($found['kind'] ?? null) === 'audio' && isset($variants['aac'])) {
            $file = Body::escape($variants['aac']['url']);

            return '<figure class="media-audio"' . self::waveform($found) . ">\n"
                . '<audio controls preload="metadata" src="' . $file . '"><a href="' . $file . "\">Download this recording</a></audio>\n"
                . self::caption($attrs)
                . "</figure>\n";
        }

        return '';
    }

    /**
     * What the site's player draws: the length, and the loudness along it. Numbers only.
     *
     * @param array<mixed> $media
     */
    private static function waveform(array $media): string
    {
        $data = '';
        if (is_float($media['seconds'] ?? null) || is_int($media['seconds'] ?? null)) {
            $data .= ' data-seconds="' . round((float) $media['seconds'], 2) . '"';
        }
        $peaks = [];
        foreach (is_array($media['peaks'] ?? null) ? $media['peaks'] : [] as $peak) {
            if (is_int($peak) || is_float($peak)) {
                // Percent of the loudest moment.
                $peaks[] = (string) max(0, min(100, (int) round((float) $peak)));
            }
        }

        return $peaks === [] ? $data : $data . ' data-peaks="' . implode(' ', $peaks) . '"';
    }

    /**
     * @param array<mixed> $attrs
     */
    private static function caption(array $attrs): string
    {
        $caption = self::text($attrs, 'caption');

        return $caption === '' ? '' : '<figcaption>' . Body::escape($caption) . "</figcaption>\n";
    }

    /**
     * @param array<mixed> $attrs
     * @param array<int, array<string, mixed>> $media
     */
    private function gallery(array $attrs, array $media): string
    {
        $html = '';
        foreach (is_array($attrs['items'] ?? null) ? $attrs['items'] : [] as $item) {
            $image = is_array($item) ? self::lookUp($item, $media) : null;
            if ($image !== null) {
                $html .= self::picture($image, self::text($item, 'alt'), self::GALLERY_SIZES);
            }
        }

        return $html === '' ? '' : "<div class=\"media-gallery\">\n$html</div>\n";
    }

    /**
     * @param array<mixed> $attrs
     * @param array<int, array<string, mixed>> $media
     *
     * @return array<string, mixed>|null
     */
    private static function lookUp(array $attrs, array $media): ?array
    {
        $id = $attrs['mediaId'] ?? null;

        return is_int($id) ? ($media[$id] ?? null) : null;
    }

    /**
     * @param array<mixed> $attrs
     */
    private static function text(array $attrs, string $name): string
    {
        return is_string($attrs[$name] ?? null) ? $attrs[$name] : '';
    }

    /**
     * A link to the full image, so it opens full-size even without JavaScript, around
     * the best format and size each browser can use.
     *
     * @param array<string, mixed> $image
     */
    private static function picture(array $image, string $alt, string $sizes, bool $lazy = true, bool $link = true): string
    {
        /** @var list<array{preset: string, format: string, width: int, height: int, url: string}> $variants */
        $variants = $image['variants'] ?? [];

        $byFormat = [];
        $full = null;
        $loop = null;
        foreach ($variants as $variant) {
            if ($variant['preset'] === 'full') {
                $full = $variant;
            } elseif ($variant['preset'] === 'loop') {
                $loop = $variant;
            } else {
                $byFormat[$variant['format']][] = $variant;
            }
        }
        $fallback = $byFormat['jpg'] ?? [];
        if ($full === null || $fallback === []) {
            return '';
        }

        if ($loop !== null) {
            return self::loop($loop, $fallback[min(1, count($fallback) - 1)], $alt);
        }

        $sources = '';
        foreach (['avif' => 'image/avif', 'webp' => 'image/webp'] as $format => $type) {
            if (isset($byFormat[$format])) {
                $sources .= '<source type="' . $type . '" srcset="' . self::srcset($byFormat[$format]) . '" sizes="' . $sizes . "\">\n";
            }
        }

        $src = $fallback[min(1, count($fallback) - 1)];
        $loading = $lazy ? ' loading="lazy" decoding="async"' : ' fetchpriority="high"';
        $picture = "<picture>\n$sources"
            . '<img src="' . Body::escape($src['url']) . '" srcset="' . self::srcset($fallback) . '" sizes="' . $sizes . '"'
            . ' width="' . $src['width'] . '" height="' . $src['height'] . '" alt="' . Body::escape($alt) . '"' . $loading . ">\n"
            . "</picture>\n";

        if (!$link) {
            return $picture;
        }

        return '<a class="lightbox" href="' . Body::escape($full['url']) . '" data-width="' . $full['width'] . '" data-height="' . $full['height'] . "\">\n"
            . $picture . "</a>\n";
    }

    /**
     * An animated GIF, as the video made from it. Muted, so browsers let it play by itself.
     * The site's script pauses it for readers who asked for less motion, and on a tap.
     *
     * @param array{preset: string, format: string, width: int, height: int, url: string} $loop
     * @param array{preset: string, format: string, width: int, height: int, url: string} $still
     */
    private static function loop(array $loop, array $still, string $alt): string
    {
        $label = $alt === '' ? ' aria-hidden="true"' : ' role="img" aria-label="' . Body::escape($alt) . '"';

        return '<video class="media-loop" src="' . Body::escape($loop['url']) . '" poster="' . Body::escape($still['url']) . '"'
            . ' width="' . $loop['width'] . '" height="' . $loop['height'] . '" autoplay loop muted playsinline disablepictureinpicture' . $label . "></video>\n";
    }

    /**
     * @param list<array{preset: string, format: string, width: int, height: int, url: string}> $variants
     */
    private static function srcset(array $variants): string
    {
        return implode(', ', array_map(
            static fn(array $variant): string => Body::escape($variant['url']) . ' ' . $variant['width'] . 'w',
            $variants,
        ));
    }
}
