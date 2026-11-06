<?php

declare(strict_types=1);

namespace Edict\Http;

use Edict\Auth\Accounts;
use Edict\Auth\LoginThrottle;
use Edict\Auth\OAuth\FlowState;
use Edict\Auth\OAuth\OAuthClient;
use Edict\Auth\OAuth\OAuthException;
use Edict\Auth\OAuth\Provider;
use Edict\Auth\OAuth\ProviderIdentity;
use Edict\Auth\OAuth\ProviderRegistry;
use Edict\Auth\Session;
use Edict\Auth\SessionStore;
use Edict\Config;
use Edict\Http\Middleware\SessionMiddleware;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;
use Twig\Environment;

/**
 * Sign-in with Twitch and Google, for readers and the allow-listed admin alike.
 */
final readonly class OAuthController
{
    public function __construct(
        private Environment $twig,
        private ProviderRegistry $providers,
        private OAuthClient $client,
        private FlowCookie $flowCookie,
        private Accounts $accounts,
        private SessionStore $sessions,
        private SessionCookie $sessionCookie,
        private LoginThrottle $throttle,
        private Config $config,
    ) {}

    public function showLogin(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $next = self::param($request->getQueryParams(), 'next');
        if (self::session($request) !== null) {
            return self::redirect($response, FlowState::safeNext($next === '' ? '/account' : $next));
        }

        return $this->renderLogin($response, Notices::fromRequest($request), FlowState::safeNext($next));
    }

    /**
     * @param array<string, string> $args
     */
    public function start(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $provider = $this->provider($request, $args);
        $next = self::param($request->getQueryParams(), 'next');

        // Anyone can link here, so a link must not be able to swap a signed-in person's session.
        if (self::session($request) !== null) {
            return self::redirect($response, FlowState::safeNext($next === '' ? '/account' : $next));
        }

        return $this->leaveFor($provider, $this->flowCookie->begin($provider->name, $next, null), $response);
    }

    /**
     * Adds a second provider to the signed-in account. A POST, so that another
     * site cannot start it (see CsrfMiddleware).
     *
     * @param array<string, string> $args
     */
    public function startLink(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $provider = $this->provider($request, $args);
        $session = self::session($request);
        if ($session === null) {
            return self::redirect($response, '/login');
        }
        if ($session->isAdmin) {
            return self::redirect($response, '/account?notice=admin-cli');
        }

        return $this->leaveFor($provider, $this->flowCookie->begin($provider->name, '/account', $session->userId), $response);
    }

    /**
     * @param array<string, string> $args
     */
    public function callback(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $provider = $this->provider($request, $args);
        $session = self::session($request);
        $home = $session === null ? '/login' : '/account';
        $ip = self::clientIp($request);

        $flow = $this->flowCookie->read($request);
        $response = $this->flowCookie->clear($response);
        $query = $request->getQueryParams();

        // Not counted as a failure: any site can make a browser request this URL, and
        // that must not be able to lock its owner out. It costs no provider request.
        if ($flow === null || $flow->provider !== $provider->name || !hash_equals($flow->state, self::param($query, 'state'))) {
            return self::redirect($response, $home . '?notice=expired');
        }

        $code = self::param($query, 'code');
        if ($code === '') {
            // The person declined at the provider, or the provider reported an error.
            return self::redirect($response, $home . '?notice=cancelled');
        }

        if ($this->throttle->isOAuthBlocked($ip)) {
            return $this->renderLogin($response->withStatus(429), Notices::text('throttled'), '/')
                ->withHeader('Retry-After', (string) LoginThrottle::WINDOW);
        }

        try {
            $identity = $this->client->identify($provider, $flow, $code);
        } catch (OAuthException $e) {
            $this->throttle->recordOAuthFailure($ip);
            if ($this->config->env !== 'test') {
                error_log('OAuth login failed: ' . $e->getMessage());
            }

            return self::redirect($response, $home . '?notice=provider-error');
        }

        return $flow->linkUserId === null
            ? $this->logIn($identity, $flow, $session, $response)
            : $this->link($identity, $flow->linkUserId, $session, $response);
    }

    private function logIn(ProviderIdentity $identity, FlowState $flow, ?Session $current, ResponseInterface $response): ResponseInterface
    {
        $account = $this->accounts->findOrCreate($identity);

        // Always a new session, so a token planted before login never becomes privileged.
        if ($current !== null) {
            $this->sessions->destroy($current);
        }
        $ttl = $account['isAdmin'] ? SessionStore::ADMIN_TTL : SessionStore::READER_TTL;
        $token = $this->sessions->create($account['id'], $ttl);

        // New readers first see the name that a provider handed us, because it will be public.
        $target = $account['created'] ? '/account?notice=welcome' : $flow->next;

        return self::redirect($this->sessionCookie->set($response, $token, $ttl), $target);
    }

    private function link(ProviderIdentity $identity, int $linkUserId, ?Session $session, ResponseInterface $response): ResponseInterface
    {
        // The account that asked must still be the one signed in, and never the admin.
        if ($session === null || $session->userId !== $linkUserId || $session->isAdmin) {
            return self::redirect($response, ($session === null ? '/login' : '/account') . '?notice=expired');
        }

        return self::redirect($response, '/account?notice=' . $this->accounts->link($linkUserId, $identity));
    }

    private function leaveFor(Provider $provider, FlowState $flow, ResponseInterface $response): ResponseInterface
    {
        return self::redirect($this->flowCookie->set($response, $flow), $this->client->authorizationUrl($provider, $flow))
            ->withHeader('Cache-Control', 'no-store');
    }

    private function renderLogin(ResponseInterface $response, ?string $notice, string $next): ResponseInterface
    {
        $response->getBody()->write($this->twig->render('auth/login.html.twig', [
            'notice' => $notice,
            'providers' => $this->providers->all(),
            'next' => $next,
        ]));

        return $response
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withHeader('Cache-Control', 'no-store');
    }

    /**
     * @param array<string, string> $args
     */
    private function provider(ServerRequestInterface $request, array $args): Provider
    {
        return $this->providers->get($args['provider'] ?? '') ?? throw new HttpNotFoundException($request);
    }

    private static function session(ServerRequestInterface $request): ?Session
    {
        $session = $request->getAttribute(SessionMiddleware::ATTRIBUTE);

        return $session instanceof Session ? $session : null;
    }

    private static function clientIp(ServerRequestInterface $request): string
    {
        $remote = $request->getServerParams()['REMOTE_ADDR'] ?? null;

        return is_string($remote) ? $remote : 'unknown';
    }

    /**
     * @param array<mixed> $params
     */
    private static function param(array $params, string $key): string
    {
        $value = $params[$key] ?? null;

        return is_string($value) ? $value : '';
    }

    private static function redirect(ResponseInterface $response, string $location): ResponseInterface
    {
        return $response->withStatus(303)->withHeader('Location', $location);
    }
}
