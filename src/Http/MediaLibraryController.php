<?php

declare(strict_types=1);

namespace Edict\Http;

use Edict\Auth\Session;
use Edict\Http\Middleware\CsrfMiddleware;
use Edict\Http\Middleware\SessionMiddleware;
use Edict\Media\MediaError;
use Edict\Media\MediaInventory;
use Edict\Media\MediaLibrary;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Twig\Environment;

/**
 * The Media page: everything uploaded, where it is used, and deleting what nobody
 * needs. Behind RequireAdmin. The editor's JSON library is MediaController.
 */
final readonly class MediaLibraryController
{
    private const PAGE_SIZE = 100;

    public function __construct(private Environment $twig, private MediaInventory $inventory, private MediaLibrary $library) {}

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $query = $request->getQueryParams();

        return $this->page($request, $response, self::view($query), match ($query['done'] ?? null) {
            'deleted' => 'Deleted, with all its versions.',
            default => null,
        }, null);
    }

    /**
     * @param array<string, string> $args
     */
    public function delete(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $body = $request->getParsedBody();
        $form = is_array($body) ? $body : [];
        $view = self::view($form);
        if (($form['confirm'] ?? null) !== 'yes') {
            return $this->page($request, $response->withStatus(422), $view, null, 'Please tick the box to confirm the deletion.');
        }

        try {
            $this->inventory->delete((int) ($args['id'] ?? 0));
        } catch (MediaError $e) {
            return $this->page($request, $response->withStatus(409), $view, null, $e->getMessage());
        }

        return $response->withStatus(303)->withHeader('Location', self::url($view + ['done' => 'deleted']));
    }

    /**
     * @param array{show: string, sort: string, page: int} $view
     */
    private function page(ServerRequestInterface $request, ResponseInterface $response, array $view, ?string $done, ?string $error): ResponseInterface
    {
        $all = $this->inventory->items(false, $view['sort']);
        $unused = array_values(array_filter($all, static fn(array $item): bool => $item['uses'] === []));
        $shown = $view['show'] === 'unused' ? $unused : $all;
        $pages = max(1, (int) ceil(count($shown) / self::PAGE_SIZE));
        $view['page'] = min($view['page'], $pages);

        $session = $request->getAttribute(SessionMiddleware::ATTRIBUTE);
        $response->getBody()->write($this->twig->render('admin/media.html.twig', [
            'items' => array_slice($shown, ($view['page'] - 1) * self::PAGE_SIZE, self::PAGE_SIZE),
            'view' => $view,
            'pages' => $pages,
            'total' => count($all),
            'unused_count' => count($unused),
            'unused_bytes' => MediaInventory::human(array_sum(array_column($unused, 'storedBytes'))),
            'storage' => $this->library->usage(),
            'done' => $done,
            'error' => $error,
            'urls' => [
                'all' => self::url(['show' => 'all'] + $view, ['page']),
                'unused' => self::url(['show' => 'unused'] + $view, ['page']),
                'newest' => self::url(['sort' => 'newest'] + $view, ['page']),
                'size' => self::url(['sort' => 'size'] + $view, ['page']),
                'previous' => self::url(['page' => $view['page'] - 1] + $view),
                'next' => self::url(['page' => $view['page'] + 1] + $view),
            ],
            'csrf_field' => CsrfMiddleware::FIELD,
            'csrf_token' => $session instanceof Session ? $session->csrfToken : '',
        ]));

        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    /**
     * What to show, from the address or the delete form. Only known values pass.
     *
     * @param array<mixed> $input
     *
     * @return array{show: string, sort: string, page: int}
     */
    private static function view(array $input): array
    {
        $page = $input['page'] ?? '1';

        return [
            'show' => ($input['show'] ?? null) === 'unused' ? 'unused' : 'all',
            'sort' => ($input['sort'] ?? null) === 'size' ? 'size' : 'newest',
            'page' => is_string($page) && preg_match('~^[1-9][0-9]{0,5}$~D', $page) === 1 ? (int) $page : 1,
        ];
    }

    /**
     * The page's address with these settings, leaving out the defaults.
     *
     * @param array<string, int|string> $view
     * @param list<string> $drop
     */
    private static function url(array $view, array $drop = []): string
    {
        $query = array_filter(
            $view,
            static fn(int|string $value, string $key): bool => !in_array($key, $drop, true)
                && !in_array([$key, $value], [['show', 'all'], ['sort', 'newest'], ['page', 1]], true),
            ARRAY_FILTER_USE_BOTH,
        );

        return '/admin/media-library' . ($query === [] ? '' : '?' . http_build_query($query));
    }
}
