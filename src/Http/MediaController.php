<?php

declare(strict_types=1);

namespace Edict\Http;

use Edict\Config;
use Edict\Media\MediaError;
use Edict\Media\MediaLibrary;
use Edict\Media\MediaStore;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;
use Slim\Psr7\Stream;

/**
 * Chunked uploads and the media library for the editor. These routes sit behind
 * RequireAdmin, except serve(), which only exists during development.
 */
final readonly class MediaController
{
    private const PAGE_SIZE = 60;
    private const CONTENT_TYPES = ['avif' => 'image/avif', 'gif' => 'image/gif', 'jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];

    public function __construct(private MediaLibrary $library, private MediaStore $store, private Config $config) {}

    public function begin(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = $request->getParsedBody();
        $name = is_array($body) ? ($body['name'] ?? null) : null;
        $size = is_array($body) ? ($body['size'] ?? null) : null;
        if (!is_string($name) || !is_int($size)) {
            return self::json($response->withStatus(400), ['error' => 'The upload request was malformed.']);
        }

        return self::guarded($response, fn(): array => [
            'id' => $this->library->beginUpload($name, $size),
            'chunkBytes' => MediaLibrary::CHUNK_BYTES,
        ]);
    }

    /**
     * @param array<string, string> $args
     */
    public function status(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        return self::guarded($response, fn(): array => ['received' => $this->library->receivedBytes($args['id'] ?? '')]);
    }

    /**
     * @param array<string, string> $args
     */
    public function chunk(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $offset = $request->getQueryParams()['offset'] ?? null;
        $chunk = $request->getBody()->detach();
        if (!is_string($offset) || !ctype_digit($offset) || !is_resource($chunk)) {
            return self::json($response->withStatus(400), ['error' => 'The chunk was malformed.']);
        }

        return self::guarded($response, fn(): array => ['received' => $this->library->appendChunk($args['id'] ?? '', (int) $offset, $chunk)]);
    }

    /**
     * @param array<string, string> $args
     */
    public function complete(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        // Hashing a large file can outlast the usual limit. S6's videos are the real test.
        set_time_limit(300);

        return self::guarded($response, fn(): array => $this->library->completeUpload($args['id'] ?? ''));
    }

    /**
     * @param array<string, string> $args
     */
    public function abort(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        return self::guarded($response, function () use ($args): array {
            $this->library->abortUpload($args['id'] ?? '');

            return ['aborted' => true];
        });
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $page = $request->getQueryParams()['page'] ?? '1';
        $offset = is_string($page) && ctype_digit($page) && $page !== '0' ? ((int) $page - 1) * self::PAGE_SIZE : 0;

        return self::json($response, ['media' => $this->library->latest(self::PAGE_SIZE, $offset)]);
    }

    /**
     * @param array<string, string> $args
     */
    public function show(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $media = $this->library->find((int) ($args['id'] ?? 0));

        return $media === null ? throw new HttpNotFoundException($request) : self::json($response, $media);
    }

    /**
     * Local development only. Everywhere else Apache serves /media/ directly, and
     * this route is never reached.
     *
     * @param array<string, string> $args
     */
    public function serve(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $path = $args['path'] ?? '';
        if (
            !$this->config->debug
            || preg_match('~^[0-9a-f]{2}/[0-9a-f]{2}/([0-9a-f]{64})-([a-z0-9-]+)\.([a-z0-9]+)$~D', $path, $match) !== 1
            || !isset(self::CONTENT_TYPES[$match[3]])
        ) {
            throw new HttpNotFoundException($request);
        }

        $file = $this->store->variantPath($match[1], $match[2], $match[3]);
        $stream = is_file($file) ? fopen($file, 'r') : false;
        if ($stream === false) {
            throw new HttpNotFoundException($request);
        }

        return $response->withBody(new Stream($stream))->withHeader('Content-Type', self::CONTENT_TYPES[$match[3]]);
    }

    /**
     * @param callable(): array<string, mixed> $work
     */
    private static function guarded(ResponseInterface $response, callable $work): ResponseInterface
    {
        try {
            return self::json($response, $work());
        } catch (MediaError $e) {
            return self::json($response->withStatus(422), ['error' => $e->getMessage()]);
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function json(ResponseInterface $response, array $data): ResponseInterface
    {
        $response->getBody()->write(json_encode($data, JSON_THROW_ON_ERROR));

        return $response->withHeader('Content-Type', 'application/json');
    }
}
