<?php

declare(strict_types=1);

namespace Edict\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Re-reads cookies from the raw header without decoding their names. Slim decodes
 * them, so a cookie named "%5F%5FHost-edict_session" would pass as the real
 * "__Host-edict_session", although browsers only enforce the __Host- rules on the
 * literal prefix. Another subdomain could plant such a cookie. Our cookie values
 * are URL-safe, so they aren't decoded either.
 */
final readonly class RawCookieMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $header = $request->getHeaderLine('Cookie');

        return $handler->handle($header === '' ? $request : $request->withCookieParams(self::parse($header)));
    }

    /**
     * @return array<string, string> the first occurrence of a name wins
     */
    public static function parse(string $header): array
    {
        $cookies = [];
        foreach (explode(';', $header) as $piece) {
            $pair = explode('=', trim($piece), 2);
            if (count($pair) === 2 && !isset($cookies[$pair[0]])) {
                $cookies[$pair[0]] = $pair[1];
            }
        }

        return $cookies;
    }
}
