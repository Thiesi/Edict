<?php

declare(strict_types=1);

namespace Edict\Http;

use Edict\Auth\Session;
use Edict\Content\Articles;
use Edict\Content\LegalTemplates;
use Edict\Http\Middleware\CsrfMiddleware;
use Edict\Http\Middleware\SessionMiddleware;
use Edict\Media\IconProcessor;
use Edict\Media\JobQueue;
use Edict\Media\MediaError;
use Edict\Media\MediaLibrary;
use Edict\Site\Look;
use Edict\Site\Settings;
use Edict\Site\Themes;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Twig\Environment;

/**
 * The site's look and frame: the theme picker, and the name, menu and footer.
 * All routes sit behind RequireAdmin.
 */
final readonly class SiteController
{
    public function __construct(
        private Environment $twig,
        private Settings $settings,
        private Articles $articles,
        private Look $look,
        private MediaLibrary $library,
        private JobQueue $jobs,
    ) {}

    public function themes(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->themesPage($request, $response, [], null);
    }

    /**
     * The accent colour, logo and icon, on the themes page.
     */
    public function saveLook(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = $request->getParsedBody();
        $form = is_array($body) ? $body : [];
        $files = $request->getUploadedFiles();
        $errors = [];
        $images = ['logo' => null, 'favicon' => null];
        foreach (array_keys($images) as $field) {
            if (($form[$field . '_remove'] ?? null) === '1') {
                $images[$field] = 0;
            }
            $file = $files[$field] ?? null;
            if ($file instanceof UploadedFileInterface && $file->getError() !== UPLOAD_ERR_NO_FILE) {
                try {
                    $images[$field] = $this->importImage($file);
                } catch (MediaError $e) {
                    $errors[$field] = $e->getMessage();
                }
            }
        }
        if ($errors === []) {
            $errors = $this->settings->saveLook($form, $images['logo'], $images['favicon']);
        }
        if ($errors !== []) {
            return $this->themesPage($request, $response->withStatus(422), $errors, $form);
        }
        if (is_int($images['favicon']) && $images['favicon'] > 0) {
            $this->jobs->enqueue(IconProcessor::JOB, ['media_id' => $images['favicon']]);
        }

        return $response->withStatus(303)->withHeader('Location', '/admin/themes?saved=1#look');
    }

    /**
     * Public: loaded by every page after the theme. Versioned in the URL, so cached for good.
     */
    public function accentCss(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $theme = $request->getQueryParams()['theme'] ?? null;
        $response->getBody()->write($this->look->accentCss(is_string($theme) ? $theme : ''));

        return $response
            ->withHeader('Content-Type', 'text/css; charset=utf-8')
            ->withHeader('Cache-Control', 'public, max-age=31536000, immutable');
    }

    /**
     * @throws MediaError
     */
    private function importImage(UploadedFileInterface $file): int
    {
        if ($file->getError() === UPLOAD_ERR_INI_SIZE || $file->getError() === UPLOAD_ERR_FORM_SIZE) {
            throw new MediaError('That image is larger than this form accepts (' . ini_get('upload_max_filesize') . '). Please use a smaller one.');
        }
        $stream = $file->getStream()->detach();
        $size = $file->getSize();
        if ($file->getError() !== UPLOAD_ERR_OK || !is_resource($stream) || $size === null) {
            throw new MediaError('The image did not arrive. Please try again.');
        }
        $media = $this->library->importStream($file->getClientFilename() ?? 'image', $size, $stream);
        if (($media['kind'] ?? null) !== 'image' || !is_int($media['id'] ?? null)) {
            throw new MediaError('Please choose an image: PNG, JPEG, WebP, AVIF or GIF.');
        }

        return $media['id'];
    }

    /**
     * @param array<string, string> $errors
     * @param array<mixed>|null $form
     */
    private function themesPage(ServerRequestInterface $request, ResponseInterface $response, array $errors, ?array $form): ResponseInterface
    {
        $site = $this->settings->site();
        $query = $request->getQueryParams();

        return $this->html($request, $response, 'admin/themes.html.twig', [
            'themes' => Themes::ALL,
            'current' => $site->theme,
            'applied' => ($query['applied'] ?? null) === '1',
            'saved' => ($query['saved'] ?? null) === '1',
            'errors' => $errors,
            'look' => [
                'accent_mode' => $form === null ? ($site->accent === '' ? 'theme' : 'own') : (($form['accent_mode'] ?? '') === 'own' ? 'own' : 'theme'),
                'accent' => $form === null ? ($site->accent === '' ? '#1a56db' : $site->accent) : (is_string($form['accent'] ?? null) ? $form['accent'] : ''),
                'logo_only' => $form === null ? $site->logoOnly : ($form['logo_only'] ?? null) === '1',
            ],
            'fitted' => $this->look->accent($site->theme),
            'logo' => $this->look->logo(),
            'has_logo' => $site->logo !== 0,
            'icons' => $this->look->icons(),
            'has_favicon' => $site->favicon !== 0,
        ]);
    }

    public function applyTheme(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = $request->getParsedBody();
        $theme = is_array($body) ? ($body['theme'] ?? null) : null;
        if (!is_string($theme) || !$this->settings->applyTheme($theme)) {
            $response->getBody()->write('There is no such theme.');

            return $response->withStatus(422)->withHeader('Content-Type', 'text/plain; charset=utf-8');
        }

        return $response->withStatus(303)->withHeader('Location', '/admin/themes?applied=1');
    }

    public function show(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $site = $this->settings->site();

        return $this->form($request, $response, [
            'title' => $site->title,
            'description' => $site->description,
            'menu' => Settings::linesOf($site->menu),
            'footer' => Settings::linesOf($site->footer),
            'imprint' => $site->imprint,
            'privacy' => $site->privacy,
        ], [], ($request->getQueryParams()['saved'] ?? null) === '1');
    }

    public function save(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = $request->getParsedBody();
        $form = is_array($body) ? $body : [];
        $errors = $this->settings->save($form);
        if ($errors === []) {
            return $response->withStatus(303)->withHeader('Location', '/admin/site?saved=1');
        }

        return $this->form($request, $response->withStatus(422), $form, $errors, false);
    }

    /**
     * @param array<string, string> $args
     */
    public function legalDraft(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $template = LegalTemplates::get($args['which'] ?? '');
        if ($template === null) {
            return $response->withStatus(404);
        }
        $id = $this->articles->createPageFrom($template['title'], $template['slug'], $template['body']);

        return $response->withStatus(303)->withHeader('Location', '/admin/articles/' . $id);
    }

    /**
     * @param array<mixed> $values
     * @param array<string, string> $errors
     */
    private function form(ServerRequestInterface $request, ResponseInterface $response, array $values, array $errors, bool $saved): ResponseInterface
    {
        return $this->html($request, $response, 'admin/site.html.twig', [
            'values' => $values,
            'errors' => $errors,
            'saved' => $saved,
            'pages' => $this->articles->publishedPages(),
            'max_links' => Settings::MAX_LINKS,
        ]);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function html(ServerRequestInterface $request, ResponseInterface $response, string $template, array $data): ResponseInterface
    {
        $session = $request->getAttribute(SessionMiddleware::ATTRIBUTE);
        $response->getBody()->write($this->twig->render($template, $data + [
            'csrf_field' => CsrfMiddleware::FIELD,
            'csrf_token' => $session instanceof Session ? $session->csrfToken : '',
        ]));

        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }
}
