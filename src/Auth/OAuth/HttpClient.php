<?php

declare(strict_types=1);

namespace Edict\Auth\OAuth;

interface HttpClient
{
    /**
     * Posts a URL-encoded form.
     *
     * @param array<string, string> $fields
     *
     * @return array{status: int, body: string}
     *
     * @throws OAuthException when no HTTP response could be obtained
     */
    public function postForm(string $url, array $fields): array;
}
