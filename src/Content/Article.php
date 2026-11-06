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
    ) {}

    public function tagNames(): string
    {
        return implode(', ', array_column($this->tags, 'name'));
    }
}
