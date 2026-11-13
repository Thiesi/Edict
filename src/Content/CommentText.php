<?php

declare(strict_types=1);

namespace Edict\Content;

/**
 * A comment is plain text. As HTML: everything escaped, blank lines become paragraphs,
 * single line breaks stay, and web addresses become links that search engines ignore.
 */
final class CommentText
{
    private const URL = '~\b(?:https?://|www\.)[^\s<>"\']+~iu';

    public static function html(string $text): string
    {
        $paragraphs = preg_split('~\n\s*\n~u', trim($text)) ?: [];
        $html = '';
        foreach ($paragraphs as $paragraph) {
            $lines = array_map(self::line(...), explode("\n", trim($paragraph)));
            $html .= '<p>' . implode("<br>\n", $lines) . "</p>\n";
        }

        return $html;
    }

    private static function line(string $line): string
    {
        $out = '';
        $offset = 0;
        if (preg_match_all(self::URL, $line, $matches, PREG_OFFSET_CAPTURE) > 0) {
            foreach ($matches[0] as [$url, $at]) {
                // A sentence's full stop or closing bracket isn't part of the address.
                $trimmed = rtrim($url, '.,;:!?)]}');
                $out .= Body::escape(substr($line, $offset, $at - $offset));
                $href = str_starts_with(strtolower($trimmed), 'www.') ? 'https://' . $trimmed : $trimmed;
                $out .= '<a href="' . Body::escape($href) . '" rel="nofollow ugc noopener">' . Body::escape($trimmed) . '</a>';
                $offset = $at + strlen($trimmed);
            }
        }

        return $out . Body::escape(substr($line, $offset));
    }
}
