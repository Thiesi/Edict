<?php

declare(strict_types=1);

namespace Edict\Content;

/**
 * One comment in a thread, with the replies shown under it.
 */
final readonly class CommentNode
{
    /**
     * @param list<CommentNode> $replies
     */
    public function __construct(
        public int $id,
        public ?int $parentId,
        public int $depth,
        public string $body,
        public string $state,
        public bool $pinned,
        public int $createdAt,
        public bool $edited,
        public ?int $authorId,
        public string $author,
        public bool $trusted,
        public bool $banned,
        public ?string $replyTo,
        public array $replies,
    ) {}
}
