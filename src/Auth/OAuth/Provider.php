<?php

declare(strict_types=1);

namespace Edict\Auth\OAuth;

final readonly class Provider
{
    /**
     * @param list<string> $issuers accepted "iss" values of the ID token
     * @param array<string, string> $authorizeParams provider-specific authorisation parameters
     */
    public function __construct(
        public string $name,
        public string $label,
        public string $clientId,
        public string $clientSecret,
        public string $authorizeUrl,
        public string $tokenUrl,
        public array $issuers,
        public string $nameClaim,
        public array $authorizeParams,
    ) {}
}
