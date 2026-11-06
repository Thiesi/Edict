<?php

declare(strict_types=1);

namespace Edict\Http;

use Edict\Auth\AdminAccount;
use Edict\Auth\LoginThrottle;
use Edict\Auth\OAuth\ProviderRegistry;
use Edict\Auth\Session;
use Edict\Auth\SessionStore;
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
        $username = is_array($body) && is_string($body['username'] ?? null) ? $body['username'] : '';
        $password = is_array($body) && is_string($body['password'] ?? null) ? $body['password'] : '';
        $code = is_array($body) && is_string($body['code'] ?? null) ? $body['code'] : '';

        $remote = $request->getServerParams()['REMOTE_ADDR'] ?? null;
        $ip = is_string($remote) ? $remote : 'unknown';
        $bucket = AdminAccount::normaliseUsername($username);

        if ($this->throttle->isBlocked($ip, $bucket)) {
            return $this->renderLogin($request, $response->withStatus(429), 'Too many failed attempts. Please try again later.')
                ->withHeader('Retry-After', (string) LoginThrottle::WINDOW);
        }

        $userId = $this->admin->verify($username, $password, $code);
        if ($userId === null) {
            $this->throttle->recordFailure($ip, $bucket);

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
