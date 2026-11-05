<?php

declare(strict_types=1);

namespace Edict\Tests;

/**
 * Pre-flight guard (CLAUDE.md, "Disk hygiene", rule 5): refuse to run the test
 * suite when gitignored scratch in the checkout holds more than the limit.
 * Mirrors SCRATCH_DIRS in scripts/clean_scratch.py.
 */
final class ScratchGuard
{
    public const DEFAULT_LIMIT = 50_000;
    public const SCRATCH_DIRS = ['.scratch', '.phpunit.cache', 'coverage'];

    /**
     * Counts files under $path, stopping as soon as the count exceeds $cap.
     */
    public static function countFiles(string $path, int $cap): int
    {
        if (!is_dir($path)) {
            return is_file($path) ? 1 : 0;
        }
        $total = 0;
        $stack = [$path];
        while ($stack !== []) {
            $dir = array_pop($stack);
            $entries = @scandir($dir);
            if ($entries === false) {
                continue;
            }
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $full = $dir . DIRECTORY_SEPARATOR . $entry;
                if (is_dir($full) && !is_link($full)) {
                    $stack[] = $full;
                } elseif (++$total > $cap) {
                    return $total;
                }
            }
        }

        return $total;
    }

    /**
     * @return string|null an error message when over the limit, null when fine
     */
    public static function check(string $root, int $limit): ?string
    {
        $total = 0;
        foreach (self::SCRATCH_DIRS as $name) {
            $total += self::countFiles($root . DIRECTORY_SEPARATOR . $name, $limit - $total);
            if ($total > $limit) {
                return sprintf(
                    'Refusing to run: scratch in %s holds more than %s files. '
                    . 'Run `python scripts/clean_scratch.py` (CLAUDE.md, "Disk hygiene"). '
                    . 'Override the limit with EDICT_SCRATCH_LIMIT only if you know why.',
                    $root,
                    number_format($limit),
                );
            }
        }

        return null;
    }

    public static function limitFromEnv(): int
    {
        $env = getenv('EDICT_SCRATCH_LIMIT');

        return is_string($env) && ctype_digit($env) ? (int) $env : self::DEFAULT_LIMIT;
    }
}
