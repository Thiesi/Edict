<?php

declare(strict_types=1);

namespace Edict\Content;

use Edict\Media\MediaLibrary;

/**
 * An article body as HTML, with its images. Images that aren't ready yet are simply
 * left out, and appear once they are.
 */
final readonly class BodyRenderer
{
    private const COLUMN_SIZES = '(max-width: 42rem) 100vw, 42rem';
    private const GALLERY_SIZES = '(max-width: 42rem) 50vw, 21rem';

    public function __construct(private MediaLibrary $library) {}

    public function html(string $json): string
    {
        $media = $this->library->readyImages(Body::mediaIds(Body::decode($json)));

        return Body::toHtml($json, function (array $node) use ($media): string {
            $attrs = is_array($node['attrs'] ?? null) ? $node['attrs'] : [];
            if (($node['type'] ?? null) === 'gallery') {
                return $this->gallery($attrs, $media);
            }

            $image = self::lookUp($attrs, $media);
            if ($image === null) {
                return '';
            }
            $caption = is_string($attrs['caption'] ?? null) ? $attrs['caption'] : '';

            return "<figure class=\"media-image\">\n"
                . self::picture($image, self::text($attrs, 'alt'), self::COLUMN_SIZES)
                . ($caption === '' ? '' : '<figcaption>' . Body::escape($caption) . "</figcaption>\n")
                . "</figure>\n";
        });
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
    private static function picture(array $image, string $alt, string $sizes): string
    {
        /** @var list<array{preset: string, format: string, width: int, height: int, url: string}> $variants */
        $variants = $image['variants'] ?? [];

        $byFormat = [];
        $full = null;
        foreach ($variants as $variant) {
            if ($variant['preset'] === 'full') {
                $full = $variant;
            } else {
                $byFormat[$variant['format']][] = $variant;
            }
        }
        $fallback = $byFormat['jpg'] ?? [];
        if ($full === null || $fallback === []) {
            return '';
        }

        $sources = '';
        foreach (['avif' => 'image/avif', 'webp' => 'image/webp'] as $format => $type) {
            if (isset($byFormat[$format])) {
                $sources .= '<source type="' . $type . '" srcset="' . self::srcset($byFormat[$format]) . '" sizes="' . $sizes . "\">\n";
            }
        }

        $src = $fallback[min(1, count($fallback) - 1)];

        return '<a class="lightbox" href="' . Body::escape($full['url']) . '" data-width="' . $full['width'] . '" data-height="' . $full['height'] . "\">\n"
            . "<picture>\n$sources"
            . '<img src="' . Body::escape($src['url']) . '" srcset="' . self::srcset($fallback) . '" sizes="' . $sizes . '"'
            . ' width="' . $src['width'] . '" height="' . $src['height'] . '" alt="' . Body::escape($alt) . '" loading="lazy" decoding="async">' . "\n"
            . "</picture>\n</a>\n";
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
