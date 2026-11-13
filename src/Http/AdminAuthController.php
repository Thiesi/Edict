<?php

declare(strict_types=1);

namespace Edict\Http;

use Edict\Auth\AdminAccount;
use Edict\Auth\LoginThrottle;
use Edict\Auth\OAuth\ProviderRegistry;
use Edict\Auth\Session;
use Edict\Auth\SessionStore;
use Edict\Content\Comments;
use Edict\Http\Middleware\CsrfMiddleware;
use Edict\Http\Middleware\SessionMiddleware;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Twig\Environment;

final readonly class AdminAuthController
{
    public function __construct(
        private Environment $twig,
        private AdminAccount $admin,
        private SessionStore $sessions,
        private LoginThrottle $throttle,
        private SessionCookie $cookie,
        private ProviderRegistry $providers,
        private Comments $comments,
    ) {}

    public function showLogin(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $session = self::session($request);
        if ($session !== null && $session->isAdmin) {
            return self::redirect($response, '/admin');
        }

        return $this->renderLogin($request, $response, null);
    }

    public function login(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = $request->getParsedBody();
        $username = self::credential($body, 'username');
        $password = self::credential($body, 'password');
        $code = self::credential($body, 'code');

        $remote = $request->getServerParams()['REMOTE_ADDR'] ?? null;
        $ip = is_string($remote) ? $remote : 'unknown';
        $bucket = AdminAccount::normaliseUsername($username);

        if ($this->throttle->isBlocked($ip, $bucket)) {
            return $this->renderLogin($request, $response->withStatus(429), 'Too many failed attempts. Please try again later.')
                ->withHeader('Retry-After', (string) LoginThrottle::WINDOW);
        }

        // Recorded before the slow check, so parallel requests can't all pass the limit.
        $this->throttle->recordFailure($ip, $bucket);

        $userId = $this->admin->verify($username, $password, $code);
        if ($userId === null) {
            return $this->renderLogin($request, $response->withStatus(401), 'Those credentials are not valid.');
        }

        $this->throttle->clear($ip, $bucket);

        // Always a new session, so a token planted before login never becomes privileged.
        $current = self::session($request);
        if ($current !== null) {
            $this->sessions->destroy($current);
        }
        $token = $this->sessions->create($userId, SessionStore::ADMIN_TTL);

        return self::redirect($this->cookie->set($response, $token, SessionStore::ADMIN_TTL), '/admin');
    }

    public function dashboard(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $session = self::session($request);
        $response->getBody()->write($this->twig->render('admin/dashboard.html.twig', [
            'display_name' => $session?->displayName,
            'held_comments' => $this->comments->heldCount(),
            'csrf_field' => CsrfMiddleware::FIELD,
            'csrf_token' => $session?->csrfToken,
        ]));

        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    public function logout(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $session = self::session($request);
        if ($session !== null) {
            $this->sessions->destroy($session);
        }

        return self::redirect($this->cookie->clear($response), '/admin/login');
    }

    public function logoutEverywhere(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $session = self::session($request);
        if ($session !== null) {
            $this->sessions->destroyAllForUser($session->userId);
        }

        return self::redirect($this->cookie->clear($response), '/admin/login');
    }

    private function renderLogin(ServerRequestInterface $request, ResponseInterface $response, ?string $error): ResponseInterface
    {
        $response->getBody()->write($this->twig->render('admin/login.html.twig', [
            'error' => $error,
            'providers' => $this->providers->all(),
            'csrf_field' => CsrfMiddleware::FIELD,
            'csrf_token' => self::session($request)?->csrfToken,
        ]));

        return $response
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withHeader('Cache-Control', 'no-store');
    }

    /**
     * Valid credentials never contain NUL bytes or invalid UTF-8, and both break
     * bcrypt or the database driver. They count as empty, so such a request takes
     * the normal failure path and reveals nothing.
     *
     * @param array<mixed>|object|null $body
     */
    public static function credential(array|object|null $body, string $name): string
    {
        $value = is_array($body) ? ($body[$name] ?? null) : null;

        return is_string($value) && !str_contains($value, "\0") && mb_check_encoding($value, 'UTF-8') ? $value : '';
    }

    private static function session(ServerRequestInterface $request): ?Session
    {
        $session = $request->getAttribute(SessionMiddleware::ATTRIBUTE);

        return $session instanceof Session ? $session : null;
    }

    private static function redirect(ResponseInterface $response, string $location): ResponseInterface
    {
        return $response->withStatus(303)->withHeader('Location', $location);
    }
}
