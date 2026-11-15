<?php

declare(strict_types=1);

namespace Edict\Backup;

use Edict\Clock;
use Edict\Content\Articles;
use Edict\Media\MediaLibrary;
use Edict\Site\Settings;

/**
 * The JSON export, which Restore reads back: articles and pages with their drafts,
 * tags and old addresses, the site settings, and the media rows. The media files
 * themselves are on disk, content-addressed, and in the host's backup.
 *
 * Comments, likes and reader accounts stay out: they belong to people signed in
 * with Google or Twitch, and are theirs, not the site's.
 */
final readonly class Export
{
    public const FORMAT = 'edict-export';
    public const VERSION = 2;

    public function __construct(
        private Articles $articles,
        private Settings $settings,
        private MediaLibrary $library,
        private Clock $clock,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(): array
    {
        $content = $this->articles->export();

        return [
            'format' => self::FORMAT,
            'version' => self::VERSION,
            'exported_at' => gmdate(DATE_ATOM, $this->clock->now()),
            'settings' => (object) $this->settings->export(),
            'media' => $this->library->export(),
            'articles' => $content['articles'],
            'tags' => $content['tags'],
        ];
    }

    public function json(): string
    {
        return json_encode($this->build(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    }
}
