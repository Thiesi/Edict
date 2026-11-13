<?php

declare(strict_types=1);

namespace Edict\Site;

/**
 * How comments work site-wide: posted at once or held, closed after some days, and
 * which words hold or reject a comment.
 */
final readonly class CommentRules
{
    /**
     * @param 'open'|'hold' $defaultMode
     * @param list<string> $words lowercase
     * @param 'hold'|'reject' $wordAction
     */
    public function __construct(
        public string $defaultMode,
        public int $autoCloseDays,
        public array $words,
        public string $wordAction,
    ) {}

    /**
     * Whole words or phrases, ignoring case, so "ass" doesn't catch "class".
     */
    public function matches(string $text): bool
    {
        $text = mb_strtolower($text);
        foreach ($this->words as $word) {
            if (preg_match('~(?<![\p{L}\p{N}])' . preg_quote($word, '~') . '(?![\p{L}\p{N}])~u', $text) === 1) {
                return true;
            }
        }

        return false;
    }
}
