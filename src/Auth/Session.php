<?php

declare(strict_types=1);

namespace Edict\Auth;

final readonly class Session
{
    public function __construct(
        public string $tokenHash,
        public int $userId,
        public string $displayName,
        public bool $isAdmin,
        public string $csrfToken,
    ) {}
}
