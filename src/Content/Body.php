<?php

declare(strict_types=1);

namespace Edict\Content;

/**
 * Article bodies as ProseMirror JSON, the editor's own format and the source of
 * truth. Two allow-lists guard it: clean() keeps only known nodes, marks and
 * attributes before anything is stored, and toHtml() builds HTML from fixed tags and
 * escapes every value. No stored HTML is ever passed through, so there is nothing
 * for an HTML sanitiser to do.
 *
 * Adding a block type means adding it here and in frontend/editor/main.js.
 */
final class Body
{
    public const EMPTY = '{"type":"doc","content":[]}';

    private const MAX_DEPTH = 100;
    private const MAX_GALLERY_ITEMS = 100;
    private const MAX_MEDIA_TEXT = 500;
    private const WRAPPERS = ['paragraph', 'bulletList', 'listItem', 'blockquote', 'table', 'tableRow'];
    private const INLINE = ['text', 'hardBreak'];
    private const BLOCKS = ['paragraph', 'heading', 'bulletList', 'orderedList', 'blockquote', 'codeBlock', 'horizontalRule', 'table', 'image', 'gallery', 'player', 'file'];

    /** What may sit where. The editor can't load a document that breaks these rules. */
    private const ALLOWED_CHILDREN = [
        'doc' => self::BLOCKS,
        'paragraph' => self::INLINE,
        'heading' => self::INLINE,
        'codeBlock' => ['text'],
        'bulletList' => ['listItem'],
        'orderedList' => ['listItem'],
        'listItem' => self::BLOCKS,
        'blockquote' => self::BLOCKS,
        'table' => ['tableRow'],
        'tableRow' => ['tableCell', 'tableHeader'],
        'tableCell' => self::BLOCKS,
        'tableHeader' => self::BLOCKS,
    ];
    private const SIMPLE_MARKS = ['bold' => 'strong', 'italic' => 'em', 'strike' => 's', 'code' => 'code'];

    /**
     * @return array{type: 'doc', content: list<array<string, mixed>>}
     */
    public static function clean(mixed $document): array
    {
        $isDocument = is_array($document) && ($document['type'] ?? null) === 'doc';

        return ['type' => 'doc', 'content' => $isDocument ? self::cleanChildren($document, 'doc', 0) : []];
    }

    /**
     * True if clean() would cut a gallery short. Refused rather than silently shortened.
     */
    public static function hasOversizedGallery(mixed $node): bool
    {
        if (!is_array($node)) {
            return false;
        }
        $items = is_array($node['attrs'] ?? null) ? ($node['attrs']['items'] ?? null) : null;
        if (is_array($items) && count($items) > self::MAX_GALLERY_ITEMS) {
            return true;
        }
        foreach (is_array($node['content'] ?? null) ? $node['content'] : [] as $child) {
            if (self::hasOversizedGallery($child)) {
                return true;
            }
        }

        return false;
    }

    /**
     * True if clean() would cut this document off. Only a crafted request gets near
     * the limit, but it must be refused rather than lose text unnoticed.
     */
    public static function isTooDeep(mixed $node, int $depth = 0): bool
    {
        if (!is_array($node)) {
            return false;
        }
        if ($depth >= self::MAX_DEPTH) {
            return true;
        }
        foreach (is_array($node['content'] ?? null) ? $node['content'] : [] as $child) {
            if (self::isTooDeep($child, $depth + 1)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<mixed> $document
     */
    public static function encode(array $document): string
    {
        return json_encode($document, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @return array{type: 'doc', content: list<array<string, mixed>>}
     */
    public static function decode(string $json): array
    {
        return self::clean(json_decode($json, true));
    }

    /**
     * @param (callable(array<string, mixed>): string)|null $media renders image and gallery blocks
     */
    public static function toHtml(string $json, ?callable $media = null): string
    {
        return self::renderAll(self::decode($json)['content'], $media);
    }

    /**
     * Every media ID a document uses, wherever it is nested.
     *
     * @param array<mixed> $node a clean document
     *
     * @return list<int>
     */
    public static function mediaIds(array $node): array
    {
        $attrs = is_array($node['attrs'] ?? null) ? $node['attrs'] : [];
        $ids = [];
        if (in_array($node['type'] ?? null, ['image', 'player', 'file'], true) && is_int($attrs['mediaId'] ?? null)) {
            $ids[] = $attrs['mediaId'];
        }
        foreach (is_array($attrs['items'] ?? null) ? $attrs['items'] : [] as $item) {
            if (is_array($item) && is_int($item['mediaId'] ?? null)) {
                $ids[] = $item['mediaId'];
            }
        }
        foreach (is_array($node['content'] ?? null) ? $node['content'] : [] as $child) {
            if (is_array($child)) {
                $ids = [...$ids, ...self::mediaIds($child)];
            }
        }

        return array_values(array_unique($ids));
    }

    public static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }

    /**
     * @param array<mixed> $parent
     *
     * @return list<array<string, mixed>>
     */
    private static function cleanChildren(array $parent, string $parentType, int $depth): array
    {
        $children = $parent['content'] ?? null;
        if (!is_array($children) || $depth >= self::MAX_DEPTH) {
            return [];
        }

        $allowed = self::ALLOWED_CHILDREN[$parentType] ?? [];
        $clean = [];
        foreach ($children as $child) {
            $node = is_array($child) && in_array($child['type'] ?? null, $allowed, true) ? self::cleanNode($child, $depth + 1) : null;
            if ($node !== null) {
                $clean[] = $node;
            }
        }

        return $clean;
    }

    /**
     * @param array<mixed> $node
     *
     * @return array<string, mixed>|null
     */
    private static function cleanNode(array $node, int $depth): ?array
    {
        $type = $node['type'] ?? null;
        $attrs = is_array($node['attrs'] ?? null) ? $node['attrs'] : [];

        if ($type === 'text') {
            return self::cleanText($node);
        }
        if ($type === 'hardBreak' || $type === 'horizontalRule') {
            return ['type' => $type];
        }
        if ($type === 'image') {
            return self::cleanImage($attrs);
        }
        if ($type === 'gallery') {
            return self::cleanGallery($attrs);
        }
        if ($type === 'player' || $type === 'file') {
            return self::cleanPlayer($type, $attrs);
        }
        if (!is_string($type)) {
            return null;
        }

        $clean = match (true) {
            in_array($type, self::WRAPPERS, true) => ['type' => $type],
            $type === 'heading' => in_array($attrs['level'] ?? null, [2, 3, 4], true)
                ? ['type' => 'heading', 'attrs' => ['level' => $attrs['level']]]
                : ['type' => 'paragraph'],
            $type === 'orderedList' => self::withStart($attrs),
            $type === 'codeBlock' => self::withLanguage($attrs),
            $type === 'tableCell', $type === 'tableHeader' => self::withSpans($type, $attrs),
            default => null,
        };
        if ($clean === null) {
            return null;
        }

        $children = self::cleanChildren($node, is_string($clean['type']) ? $clean['type'] : '', $depth);

        return $children === [] ? $clean : $clean + ['content' => $children];
    }

    /**
     * @param array<mixed> $node
     *
     * @return array<string, mixed>|null
     */
    private static function cleanText(array $node): ?array
    {
        $text = is_string($node['text'] ?? null) ? str_replace("\0", '', $node['text']) : '';
        if ($text === '') {
            return null; // ProseMirror refuses empty text nodes.
        }

        $marks = [];
        foreach (is_array($node['marks'] ?? null) ? $node['marks'] : [] as $mark) {
            if (!is_array($mark)) {
                continue;
            }
            $type = $mark['type'] ?? null;
            if (is_string($type) && isset(self::SIMPLE_MARKS[$type])) {
                $marks[] = ['type' => $type];
            } elseif ($type === 'link' && is_array($mark['attrs'] ?? null) && is_string($mark['attrs']['href'] ?? null)) {
                $href = self::safeHref($mark['attrs']['href']);
                if ($href !== null) {
                    $marks[] = ['type' => 'link', 'attrs' => ['href' => $href]];
                }
            }
        }

        return $marks === [] ? ['type' => 'text', 'text' => $text] : ['type' => 'text', 'text' => $text, 'marks' => $marks];
    }

    /**
     * @param array<mixed> $attrs
     *
     * @return array<string, mixed>|null
     */
    private static function cleanImage(array $attrs): ?array
    {
        $id = $attrs['mediaId'] ?? null;
        if (!is_int($id) || $id <= 0) {
            return null;
        }

        return ['type' => 'image', 'attrs' => [
            'mediaId' => $id,
            'alt' => self::shortText($attrs['alt'] ?? null),
            'caption' => self::shortText($attrs['caption'] ?? null),
        ]];
    }

    /**
     * @param array<mixed> $attrs
     *
     * @return array<string, mixed>|null
     */
    private static function cleanGallery(array $attrs): ?array
    {
        $items = [];
        foreach (is_array($attrs['items'] ?? null) ? $attrs['items'] : [] as $item) {
            $id = is_array($item) ? ($item['mediaId'] ?? null) : null;
            if (is_int($id) && $id > 0 && count($items) < self::MAX_GALLERY_ITEMS) {
                $items[] = ['mediaId' => $id, 'alt' => self::shortText($item['alt'] ?? null)];
            }
        }

        return $items === [] ? null : ['type' => 'gallery', 'attrs' => ['items' => $items]];
    }

    /**
     * @param array<mixed> $attrs
     *
     * @return array<string, mixed>|null
     */
    private static function cleanPlayer(string $type, array $attrs): ?array
    {
        $id = $attrs['mediaId'] ?? null;
        if (!is_int($id) || $id <= 0) {
            return null;
        }

        return ['type' => $type, 'attrs' => ['mediaId' => $id, 'caption' => self::shortText($attrs['caption'] ?? null)]];
    }

    private static function shortText(mixed $value): string
    {
        return is_string($value) ? mb_substr(str_replace("\0", '', $value), 0, self::MAX_MEDIA_TEXT) : '';
    }

    /**
     * @param array<mixed> $attrs
     *
     * @return array<string, mixed>
     */
    private static function withStart(array $attrs): array
    {
        $start = $attrs['start'] ?? null;

        return is_int($start) && $start !== 1 && $start >= 0 && $start <= 1_000_000
            ? ['type' => 'orderedList', 'attrs' => ['start' => $start]]
            : ['type' => 'orderedList'];
    }

    /**
     * @param array<mixed> $attrs
     *
     * @return array<string, mixed>
     */
    private static function withLanguage(array $attrs): array
    {
        $language = $attrs['language'] ?? null;

        return is_string($language) && preg_match('/^[a-z0-9+#-]{1,30}$/D', $language) === 1
            ? ['type' => 'codeBlock', 'attrs' => ['language' => $language]]
            : ['type' => 'codeBlock'];
    }

    /**
     * @param array<mixed> $attrs
     *
     * @return array<string, mixed>
     */
    private static function withSpans(string $type, array $attrs): array
    {
        $clean = [];
        foreach (['colspan', 'rowspan'] as $name) {
            $value = $attrs[$name] ?? null;
            if (is_int($value) && $value > 1 && $value <= 100) {
                $clean[$name] = $value;
            }
        }

        return $clean === [] ? ['type' => $type] : ['type' => $type, 'attrs' => $clean];
    }

    /**
     * Web, mail and phone addresses, same-site paths and anchors. "www.example.org"
     * means the web address people expect. Everything else, such as "javascript:",
     * loses its link and stays plain text.
     */
    private static function safeHref(string $href): ?string
    {
        $href = str_replace(' ', '%20', trim($href));
        if (preg_match('/^www\./i', $href) === 1) {
            $href = 'https://' . $href;
        }
        if (preg_match('/[\x00-\x20\x7f\\\\]/', $href) === 1 || strlen($href) > 2048) {
            return null;
        }

        return preg_match('~^(https?://|mailto:|tel:|#|/(?!/))~i', $href) === 1 ? $href : null;
    }

    /**
     * @param list<array<string, mixed>> $nodes
     * @param (callable(array<string, mixed>): string)|null $media
     */
    private static function renderAll(array $nodes, ?callable $media): string
    {
        return implode('', array_map(static fn(array $node): string => self::render($node, $media), $nodes));
    }

    /**
     * @param array<string, mixed> $node
     * @param (callable(array<string, mixed>): string)|null $media
     */
    private static function render(array $node, ?callable $media): string
    {
        /** @var list<array<string, mixed>> $children */
        $children = $node['content'] ?? [];
        $inner = self::renderAll($children, $media);
        /** @var array<string, mixed> $attrs */
        $attrs = $node['attrs'] ?? [];

        return match ($node['type'] ?? null) {
            'text' => self::renderText($node),
            'hardBreak' => '<br>',
            'horizontalRule' => "<hr>\n",
            'image', 'gallery', 'player', 'file' => $media === null ? '' : $media($node),
            'paragraph' => "<p>$inner</p>\n",
            'heading' => self::heading($attrs, $inner),
            'bulletList' => "<ul>\n$inner</ul>\n",
            'orderedList' => isset($attrs['start']) ? '<ol start="' . self::int($attrs['start']) . "\">\n$inner</ol>\n" : "<ol>\n$inner</ol>\n",
            'listItem' => "<li>$inner</li>\n",
            'blockquote' => "<blockquote>\n$inner</blockquote>\n",
            'codeBlock' => self::codeBlock($attrs, $inner),
            'table' => "<table><tbody>\n$inner</tbody></table>\n",
            'tableRow' => "<tr>$inner</tr>\n",
            'tableCell' => '<td' . self::spans($attrs) . ">$inner</td>",
            'tableHeader' => '<th' . self::spans($attrs) . ">$inner</th>",
            default => '',
        };
    }

    /**
     * @param array<string, mixed> $attrs
     */
    private static function heading(array $attrs, string $inner): string
    {
        $tag = 'h' . self::int($attrs['level'] ?? 2);

        return "<$tag>$inner</$tag>\n";
    }

    /**
     * @param array<string, mixed> $attrs
     */
    private static function codeBlock(array $attrs, string $inner): string
    {
        $language = $attrs['language'] ?? null;
        $class = is_string($language) ? ' class="language-' . self::escape($language) . '"' : '';

        return "<pre><code$class>$inner</code></pre>\n";
    }

    /**
     * @param array<string, mixed> $attrs
     */
    private static function spans(array $attrs): string
    {
        $html = '';
        foreach (['colspan', 'rowspan'] as $name) {
            if (isset($attrs[$name])) {
                $html .= " $name=\"" . self::int($attrs[$name]) . '"';
            }
        }

        return $html;
    }

    /**
     * @param array<string, mixed> $node
     */
    private static function renderText(array $node): string
    {
        $html = self::escape(is_string($node['text'] ?? null) ? $node['text'] : '');

        /** @var list<array<string, mixed>> $marks */
        $marks = $node['marks'] ?? [];
        foreach ($marks as $mark) {
            $type = $mark['type'] ?? null;
            if (is_string($type) && isset(self::SIMPLE_MARKS[$type])) {
                $tag = self::SIMPLE_MARKS[$type];
                $html = "<$tag>$html</$tag>";
            } elseif ($type === 'link') {
                /** @var array{href: string} $attrs */
                $attrs = $mark['attrs'];
                $html = '<a href="' . self::escape($attrs['href']) . '" rel="noopener">' . $html . '</a>';
            }
        }

        return $html;
    }

    private static function int(mixed $value): int
    {
        return is_int($value) ? $value : 1;
    }
}
