<?php

declare(strict_types=1);

namespace Edict\Auth\OAuth;

final readonly class ProviderIdentity
{
    public function __construct(
        public string $provider,
        public string $subject,
        public string $name,
    ) {}
}
