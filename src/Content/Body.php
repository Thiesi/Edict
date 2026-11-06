<?php

declare(strict_types=1);

namespace Edict\Content;

/**
 * Article bodies as ProseMirror JSON, which the editor (S4) will produce directly.
 * HTML only ever comes out of toHtml(), which is an allow-list: anything it doesn't
 * know is left out, and all text is escaped.
 */
final class Body
{
    public static function fromPlainText(string $text): string
    {
        $paragraphs = [];
        foreach (preg_split('/\n{2,}/', trim(str_replace(["\r\n", "\r"], "\n", $text))) ?: [] as $paragraph) {
            if ($paragraph === '') {
                continue;
            }

            $content = [];
            foreach (explode("\n", $paragraph) as $i => $line) {
                if ($i > 0) {
                    $content[] = ['type' => 'hardBreak'];
                }
                if ($line !== '') {
                    $content[] = ['type' => 'text', 'text' => $line];
                }
            }
            $paragraphs[] = ['type' => 'paragraph', 'content' => $content];
        }

        return json_encode(['type' => 'doc', 'content' => $paragraphs], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    public static function toPlainText(string $json): string
    {
        $paragraphs = [];
        foreach (self::children(self::decode($json)) as $node) {
            if (is_array($node) && ($node['type'] ?? null) === 'paragraph') {
                $text = '';
                foreach (self::children($node) as $inline) {
                    if (is_array($inline)) {
                        $text .= ($inline['type'] ?? null) === 'hardBreak' ? "\n" : self::text($inline);
                    }
                }
                $paragraphs[] = $text;
            }
        }

        return implode("\n\n", $paragraphs);
    }

    public static function toHtml(string $json): string
    {
        $html = '';
        foreach (self::children(self::decode($json)) as $node) {
            if (is_array($node) && ($node['type'] ?? null) === 'paragraph') {
                $html .= '<p>' . self::inlineHtml($node) . "</p>\n";
            }
        }

        return $html;
    }

    /**
     * @param array<mixed> $paragraph
     */
    private static function inlineHtml(array $paragraph): string
    {
        $html = '';
        foreach (self::children($paragraph) as $inline) {
            if (is_array($inline)) {
                $html .= match ($inline['type'] ?? null) {
                    'text' => htmlspecialchars(self::text($inline), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8'),
                    'hardBreak' => '<br>',
                    default => '',
                };
            }
        }

        return $html;
    }

    /**
     * @return array<mixed>
     */
    private static function decode(string $json): array
    {
        $document = json_decode($json, true);

        return is_array($document) ? $document : [];
    }

    /**
     * @param array<mixed> $node
     *
     * @return array<mixed>
     */
    private static function children(array $node): array
    {
        $content = $node['content'] ?? null;

        return is_array($content) ? $content : [];
    }

    /**
     * @param array<mixed> $node
     */
    private static function text(array $node): string
    {
        $text = $node['text'] ?? null;

        return is_string($text) ? $text : '';
    }
}
