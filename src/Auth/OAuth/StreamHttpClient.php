<?php

declare(strict_types=1);

namespace Edict\Auth\OAuth;

/**
 * PHP's HTTP stream wrapper with certificate verification on and redirects off.
 * Needs only ext-openssl and allow_url_fopen.
 */
final readonly class StreamHttpClient implements HttpClient
{
    private const TIMEOUT_SECONDS = 10;
    private const MAX_BYTES = 1_048_576;

    public function postForm(string $url, array $fields): array
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/x-www-form-urlencoded\r\nAccept: application/json\r\n",
                'content' => http_build_query($fields, '', '&', PHP_QUERY_RFC1738),
                'timeout' => self::TIMEOUT_SECONDS,
                'ignore_errors' => true,
                'follow_location' => 0,
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);

        $stream = @fopen($url, 'r', false, $context);
        if ($stream === false) {
            throw new OAuthException('Could not reach ' . (string) parse_url($url, PHP_URL_HOST));
        }

        try {
            $meta = stream_get_meta_data($stream);
            $body = stream_get_contents($stream, self::MAX_BYTES);
        } finally {
            fclose($stream);
        }

        $headers = $meta['wrapper_data'] ?? null;
        $statusLine = is_array($headers) ? ($headers[0] ?? null) : null;
        if (
            $body === false
            || !is_string($statusLine)
            || preg_match('#^HTTP/\S+ (\d{3})#', $statusLine, $match) !== 1
        ) {
            throw new OAuthException('Unreadable response from ' . (string) parse_url($url, PHP_URL_HOST));
        }

        return ['status' => (int) $match[1], 'body' => $body];
    }
}
