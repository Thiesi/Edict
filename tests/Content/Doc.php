<?php

declare(strict_types=1);

namespace Edict\Tests\Content;

/**
 * A minimal editor document. An empty line starts a new paragraph.
 */
final class Doc
{
    public static function text(string $text): string
    {
        $paragraphs = [];
        foreach (preg_split('/\n{2,}/', trim($text)) ?: [] as $paragraph) {
            if ($paragraph !== '') {
                $paragraphs[] = ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $paragraph]]];
            }
        }

        return (string) json_encode(['type' => 'doc', 'content' => $paragraphs]);
    }
}
