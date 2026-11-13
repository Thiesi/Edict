<?php

declare(strict_types=1);

namespace Edict\Site;

/**
 * What every public page needs: the look, the name, and the links around the content.
 */
final readonly class SiteSettings
{
    /**
     * @param list<array{label: string, url: string}> $menu
     * @param list<array{label: string, url: string}> $footer
     */
    public function __construct(
        public string $theme,
        public string $title,
        public string $description,
        public array $menu,
        public array $footer,
        public string $imprint,
        public string $privacy,
    ) {}

    public function withTheme(string $theme): self
    {
        return new self($theme, $this->title, $this->description, $this->menu, $this->footer, $this->imprint, $this->privacy);
    }
}
