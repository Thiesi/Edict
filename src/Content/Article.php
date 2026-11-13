<?php

declare(strict_types=1);

namespace Edict\Content;

final readonly class Article
{
    /**
     * @param list<array{slug: string, name: string}> $tags
     */
    public function __construct(
        public int $id,
        public string $kind,
        public string $slug,
        public string $lang,
        public string $title,
        public string $teaser,
        public string $bodyJson,
        public string $status,
        public ?int $publishedAt,
        public int $updatedAt,
        public ?int $deletedAt,
        public array $tags,
        public ?string $draftJson,
        public int $draftVersion,
        public ?int $coverMediaId = null,
        public string $coverAlt = '',
        public ?int $revisedAt = null,
    ) {}

    /**
     * What the editor shows: the draft, or the live version if nothing differs.
     */
    public function input(): ArticleInput
    {
        return $this->draftJson !== null ? ArticleInput::fromJson($this->draftJson) : $this->liveInput();
    }

    /**
     * What readers see now, as the editor's fields.
     */
    public function liveInput(): ArticleInput
    {
        return new ArticleInput(
            $this->title,
            $this->slug,
            $this->lang,
            $this->teaser,
            $this->bodyJson,
            implode(', ', array_column($this->tags, 'name')),
            $this->coverMediaId,
            $this->coverAlt,
        );
    }

    /**
     * The title in admin lists. A brand-new draft has none yet.
     */
    public function workingTitle(): string
    {
        $title = $this->input()->title;

        return $title === '' ? 'Untitled' : $title;
    }

    public function isPublished(): bool
    {
        return $this->status === 'published';
    }

    public function hasPendingChanges(): bool
    {
        return $this->isPublished() && $this->draftJson !== null;
    }

    /**
     * This article as its draft would look, for the preview.
     */
    public function asDraft(): self
    {
        $input = $this->input();

        return new self(
            $this->id,
            $this->kind,
            $this->slug,
            in_array($input->lang, ['de', 'en'], true) ? $input->lang : $this->lang,
            $input->title === '' ? 'Untitled' : $input->title,
            $input->teaser,
            $input->body,
            $this->status,
            $this->publishedAt,
            $this->updatedAt,
            $this->deletedAt,
            $input->tagList(),
            $this->draftJson,
            $this->draftVersion,
            $input->cover,
            $input->coverAlt,
            $this->revisedAt,
        );
    }
}
