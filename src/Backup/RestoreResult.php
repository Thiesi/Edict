<?php

declare(strict_types=1);

namespace Edict\Backup;

/**
 * What a restore wrote, for the dashboard and the command line.
 */
final readonly class RestoreResult
{
    /**
     * @param list<string> $mediaMissing the files not on disk, by name and hash
     * @param list<string> $notes anything else the owner should know
     */
    public function __construct(
        public int $version,
        public int $articles,
        public int $pages,
        public int $tags,
        public int $mediaRelinked,
        public array $mediaMissing,
        public bool $settingsRestored,
        public array $notes,
    ) {}

    /**
     * @return list<string>
     */
    public function lines(): array
    {
        $lines = [
            self::count($this->articles, 'article') . ', ' . self::count($this->pages, 'page') . ', ' . self::count($this->tags, 'tag') . ' restored.',
            self::count($this->mediaRelinked, 'media file') . ' relinked, ' . count($this->mediaMissing) . ' missing.',
            $this->settingsRestored ? 'Settings restored.' : 'No settings in the file; the current ones were kept.',
        ];
        if ($this->mediaMissing !== []) {
            $lines[] = 'Not on disk, so skipped: ' . implode(', ', $this->mediaMissing) . '.';
        }
        if ($this->mediaRelinked > 0) {
            $lines[] = 'The worker is making thumbnails and other versions again; images appear as it gets to them.';
        }

        return [...$lines, ...$this->notes];
    }

    private static function count(int $n, string $noun): string
    {
        return $n . ' ' . $noun . ($n === 1 ? '' : 's');
    }
}
